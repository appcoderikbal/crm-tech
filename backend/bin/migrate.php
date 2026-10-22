<?php

declare(strict_types=1);

/**
 * Applies pending database migrations.
 *
 *   php backend/bin/migrate.php            # apply pending
 *   php backend/bin/migrate.php --status   # list applied/pending
 *
 * CLI only: running it over HTTP would expose schema changes to the internet.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Techzapp\Crm\Bootstrap;
use Techzapp\Crm\Core\Migrator;

Bootstrap::init('cli');

$migrator = new Migrator(dirname(__DIR__) . '/migrations');

if (in_array('--status', $argv, true)) {
    $applied = $migrator->appliedVersions();
    $pending = array_keys($migrator->pendingFiles());

    fwrite(STDOUT, "Applied migrations (" . count($applied) . "):\n");
    foreach ($applied as $version) {
        fwrite(STDOUT, "  [x] {$version}\n");
    }
    fwrite(STDOUT, "Pending migrations (" . count($pending) . "):\n");
    foreach ($pending as $version) {
        fwrite(STDOUT, "  [ ] {$version}\n");
    }
    exit(0);
}

fwrite(STDOUT, "Running migrations...\n");

try {
    $applied = $migrator->migrate(true);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, $applied === []
    ? "Database is already up to date.\n"
    : 'Applied ' . count($applied) . " migration(s).\n");
exit(0);
