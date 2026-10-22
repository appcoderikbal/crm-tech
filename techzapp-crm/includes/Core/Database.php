<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use RuntimeException;
use Throwable;
use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database access through $wpdb.
 *
 * The service layer was written against named placeholders and unprefixed
 * table names, which is both readable and portable. This adapter keeps that
 * style and translates on the way out:
 *
 *   SELECT * FROM {contacts} WHERE id = :id
 *      ->  SELECT * FROM wp_techzapp_contacts WHERE id = %d
 *
 * Everything still goes through $wpdb->prepare(), so the SQL-injection
 * guarantees are WordPress's own.
 */
final class Database
{
    /** Prefix applied to every plugin table, after the site's own prefix. */
    public const TABLE_PREFIX = 'techzapp_';

    /** Tables owned by the plugin. Used by the installer and uninstaller. */
    public const TABLES = [
        'contacts',
        'lists',
        'list_contacts',
        'suppressions',
        'suppression_events',
        'campaigns',
        'campaign_links',
        'campaign_recipients',
        'campaign_stats',
        'email_events',
        'provider_notifications',
        'import_jobs',
        'import_row_errors',
        'processing_locks',
        'send_counters',
        'rate_limit_counters',
        'audit_logs',
        'alerts',
        'cron_heartbeats',
    ];

    private static int $transactionDepth = 0;

    public static function wpdb(): wpdb
    {
        global $wpdb;
        return $wpdb;
    }

    /** Fully-qualified name of a plugin table. */
    public static function table(string $name): string
    {
        return self::wpdb()->prefix . self::TABLE_PREFIX . $name;
    }

    /** Expands every {table} token in a statement. */
    public static function expandTables(string $sql): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}/',
            static fn (array $m): string => self::table($m[1]),
            $sql
        );
    }

    /**
     * Converts named placeholders to a $wpdb->prepare() call.
     *
     * Two details matter:
     *
     *  - Literal percent signs (DATE_FORMAT patterns, LIKE 'Permanent%') must
     *    be doubled before prepare() sees them, or it misreads them as format
     *    specifiers. They are escaped first, so the placeholders inserted
     *    afterwards are the only real ones.
     *  - prepare() renders a null argument as an empty string, not NULL. Null
     *    values are therefore written straight into the SQL as the NULL
     *    keyword — a constant, never caller data, so nothing is injectable.
     *
     * @param array<string,mixed> $params
     * @return array{0:string, 1:list<scalar>}
     */
    public static function compile(string $sql, array $params): array
    {
        $sql = self::expandTables($sql);

        if ($params === []) {
            return [$sql, []];
        }

        // Protect literal percent signs before introducing our own.
        $sql = str_replace('%', '%%', $sql);

        // A list of values means positional "?" placeholders, which the bulk
        // inserts and IN(...) clauses use because the number of values is only
        // known at runtime.
        if (array_is_list($params)) {
            return self::compilePositional($sql, $params);
        }

        $ordered = [];
        $compiled = (string) preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $match) use ($params, &$ordered): string {
                $name = $match[1];

                if (!array_key_exists($name, $params)) {
                    // Not one of ours (for example a MySQL user variable).
                    return $match[0];
                }

                $value = $params[$name];

                if ($value === null) {
                    return 'NULL';
                }
                if (is_bool($value)) {
                    $ordered[] = $value ? 1 : 0;
                    return '%d';
                }
                if (is_int($value)) {
                    $ordered[] = $value;
                    return '%d';
                }
                if (is_float($value)) {
                    $ordered[] = $value;
                    return '%f';
                }

                $ordered[] = (string) $value;
                return '%s';
            },
            $sql
        );

        return [$compiled, $ordered];
    }

    /**
     * Positional form: each "?" takes the next value from the list.
     *
     * @param list<mixed> $params
     * @return array{0:string, 1:list<scalar>}
     */
    private static function compilePositional(string $sql, array $params): array
    {
        $index = 0;
        $ordered = [];

        $compiled = (string) preg_replace_callback(
            '/\?/',
            static function () use ($params, &$index, &$ordered): string {
                if (!array_key_exists($index, $params)) {
                    $index++;
                    return 'NULL';
                }

                $value = $params[$index];
                $index++;

                if ($value === null) {
                    return 'NULL';
                }
                if (is_bool($value)) {
                    $ordered[] = $value ? 1 : 0;
                    return '%d';
                }
                if (is_int($value)) {
                    $ordered[] = $value;
                    return '%d';
                }
                if (is_float($value)) {
                    $ordered[] = $value;
                    return '%f';
                }

                $ordered[] = (string) $value;
                return '%s';
            },
            $sql
        );

        return [$compiled, $ordered];
    }

    /**
     * Prepares a statement, or returns it untouched when it has no arguments.
     *
     * @param array<string,mixed> $params
     */
    private static function prepare(string $sql, array $params): string
    {
        [$compiled, $ordered] = self::compile($sql, $params);

        if ($ordered === []) {
            // Nothing to bind. Calling prepare() here would trigger a
            // _doing_it_wrong notice, and the percent-escaping above only
            // applies when prepare() is involved.
            return self::expandTables($sql);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $compiled is built above with %s/%d/%f placeholders only.
        return self::wpdb()->prepare($compiled, $ordered);
    }

    /** Throws when the last $wpdb operation failed. */
    private static function assertNoError(string $sql): void
    {
        $wpdb = self::wpdb();
        if ($wpdb->last_error === '') {
            return;
        }

        $message = $wpdb->last_error;
        Logger::error('database.query_failed', [
            'error' => $message,
            'sql'   => substr($sql, 0, 400),
        ]);

        throw new DatabaseException($message);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $wpdb = self::wpdb();
        $statement = self::prepare($sql, $params);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($statement, ARRAY_A);
        self::assertNoError($statement);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        $wpdb = self::wpdb();
        $statement = self::prepare($sql, $params);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($statement, ARRAY_A);
        self::assertNoError($statement);

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $wpdb = self::wpdb();
        $statement = self::prepare($sql, $params);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $value = $wpdb->get_var($statement);
        self::assertNoError($statement);

        return $value;
    }

    /**
     * Runs a write and returns the number of affected rows.
     *
     * @param array<string,mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        $wpdb = self::wpdb();
        $statement = self::prepare($sql, $params);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query($statement);
        self::assertNoError($statement);

        return $result === false ? 0 : (int) $result;
    }

    public static function lastInsertId(): int
    {
        return (int) self::wpdb()->insert_id;
    }

    /**
     * Runs $work inside a transaction, rolling back on any throwable.
     *
     * $wpdb has no nested-transaction support, so an inner call joins the
     * outer transaction rather than starting a second one.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $wpdb = self::wpdb();
        $owner = self::$transactionDepth === 0;

        if ($owner) {
            $wpdb->query('START TRANSACTION');
        }
        self::$transactionDepth++;

        try {
            $result = $work();

            self::$transactionDepth--;
            if ($owner) {
                $wpdb->query('COMMIT');
            }

            return $result;
        } catch (Throwable $e) {
            self::$transactionDepth--;
            if ($owner) {
                $wpdb->query('ROLLBACK');
            }
            throw $e;
        }
    }

    public static function inTransaction(): bool
    {
        return self::$transactionDepth > 0;
    }

    /** True when the throwable represents a duplicate-key violation. */
    public static function isDuplicateKey(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), '1062');
    }

    /** True when the throwable is a deadlock or lock-wait timeout worth retrying. */
    public static function isRetryableLockError(Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'Deadlock found')
            || str_contains($message, 'Lock wait timeout');
    }

    /**
     * The current time in UTC.
     *
     * MySQL's own NOW() follows the server timezone, which on shared hosting
     * is rarely UTC. Every timestamp this plugin writes comes from here or
     * from UTC_TIMESTAMP(), never from NOW().
     */
    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Resets the cached transaction depth. Tests only. */
    public static function resetState(): void
    {
        self::$transactionDepth = 0;
    }
}

/** Thrown when $wpdb reports an error, so callers can catch it like a PDOException. */
final class DatabaseException extends RuntimeException
{
}
