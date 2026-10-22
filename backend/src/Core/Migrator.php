<?php

declare(strict_types=1);

namespace Techzapp\Crm\Core;

use PDO;
use RuntimeException;

/**
 * Applies .sql migration files in filename order, recording each in
 * schema_migrations so repeated runs are no-ops.
 */
final class Migrator
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return list<string> Versions applied by this call. */
    public function migrate(bool $verbose = false): array
    {
        $pdo = Database::connection();
        $this->ensureRegistry($pdo);

        $applied = [];
        foreach ($this->pendingFiles() as $version => $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration {$file}");
            }

            foreach ($this->splitStatements($sql) as $statement) {
                try {
                    $pdo->exec($statement);
                } catch (\PDOException $e) {
                    throw new RuntimeException(
                        "Migration {$version} failed: " . $e->getMessage() .
                        "\nStatement: " . substr($statement, 0, 300),
                        0,
                        $e
                    );
                }
            }

            $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?) ON DUPLICATE KEY UPDATE version = version')
                ->execute([$version]);

            $applied[] = $version;
            if ($verbose) {
                fwrite(STDOUT, "  applied {$version}\n");
            }
        }

        return $applied;
    }

    /** @return list<string> */
    public function appliedVersions(): array
    {
        $this->ensureRegistry(Database::connection());
        return array_map(
            static fn (array $row): string => (string) $row['version'],
            Database::all('SELECT version FROM schema_migrations ORDER BY version')
        );
    }

    /** @return array<string,string> version => path */
    public function pendingFiles(): array
    {
        $applied = array_flip($this->appliedVersions());
        $pending = [];
        foreach (glob($this->directory . '/*.sql') ?: [] as $file) {
            $version = basename($file, '.sql');
            if (!isset($applied[$version])) {
                $pending[$version] = $file;
            }
        }
        ksort($pending, SORT_NATURAL);
        return $pending;
    }

    private function ensureRegistry(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
               version    VARCHAR(100) NOT NULL,
               applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (version)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Splits a migration file on semicolons that terminate a statement,
     * ignoring semicolons inside quoted strings or comments.
     *
     * @return list<string>
     */
    public function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingle = $inDouble = $inBacktick = $inLineComment = $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }
            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            if ($char === "'" && !$inDouble && !$inBacktick) {
                $escaped = $this->isEscaped($buffer);
                if (!$escaped) {
                    $inSingle = !$inSingle;
                }
            } elseif ($char === '"' && !$inSingle && !$inBacktick) {
                if (!$this->isEscaped($buffer)) {
                    $inDouble = !$inDouble;
                }
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private function isEscaped(string $buffer): bool
    {
        $backslashes = 0;
        for ($i = strlen($buffer) - 1; $i >= 0 && $buffer[$i] === '\\'; $i--) {
            $backslashes++;
        }
        return $backslashes % 2 === 1;
    }
}
