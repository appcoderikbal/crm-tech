<?php

declare(strict_types=1);

namespace Techzapp\Crm\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Thin PDO wrapper. Every query in the application goes through a prepared
 * statement; no caller ever interpolates a value into SQL.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = Config::load();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->string('database.host', 'localhost'),
            $config->int('database.port', 3306),
            $config->string('database.name'),
            $config->string('database.charset', 'utf8mb4')
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                $config->string('database.user'),
                $config->string('database.password'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::ATTR_TIMEOUT            => $config->int('database.timeout', 5),
                ]
            );
            // Application timestamps are stored and compared in UTC everywhere.
            self::$pdo->exec("SET time_zone = '+00:00'");
            self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            Logger::error('database.connect_failed', ['message' => $e->getMessage()]);
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        return self::$pdo;
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function isConnected(): bool
    {
        return self::$pdo instanceof PDO;
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::run($sql, $params)->fetchAll();
        return $rows;
    }

    /** @param array<string|int,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string|int,mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::connection()->lastInsertId();
    }

    /**
     * Runs $work inside a transaction, rolling back on any throwable.
     *
     * @template T
     * @param callable(PDO):T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::connection();
        $owner = !$pdo->inTransaction();
        if ($owner) {
            $pdo->beginTransaction();
        }
        try {
            $result = $work($pdo);
            if ($owner) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($owner && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** True when the exception is a duplicate-key violation. */
    public static function isDuplicateKey(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        return ($e->errorInfo[1] ?? null) === 1062 || str_contains($e->getMessage(), 'Duplicate entry');
    }

    /** True when the exception is a deadlock or lock-wait timeout worth retrying. */
    public static function isRetryableLockError(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }
        $code = $e->errorInfo[1] ?? null;
        return $code === 1213 || $code === 1205;
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
