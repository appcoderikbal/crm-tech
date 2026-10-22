<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests;

use PHPUnit\Framework\TestCase;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Settings;
use Techzapp\Crm\Services\ContactService;
use Techzapp\Crm\Services\Mail\Mailer;

/**
 * Base class for tests that need the database. Each test starts from an empty
 * set of application tables so results never depend on test ordering.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** Tables cleared between tests, children before parents. */
    protected const TABLES = [
        'email_events', 'provider_notifications', 'campaign_recipients', 'campaign_links',
        'campaign_stats', 'campaigns', 'import_row_errors', 'import_jobs',
        'list_contacts', 'lists', 'suppression_events', 'suppressions', 'contacts',
        'alerts', 'audit_logs', 'cron_heartbeats', 'processing_locks',
        'send_counters', 'rate_limit_counters', 'login_attempts', 'users',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('CRM_TEST_DATABASE_CONFIGURED') || !CRM_TEST_DATABASE_CONFIGURED) {
            $this->markTestSkipped('Set TEST_DB_NAME to run integration tests.');
        }

        $this->truncateAll();

        // Settings persist in their own table; clearing it makes every test
        // start from the code defaults rather than the previous test's values.
        Database::execute('DELETE FROM system_settings');
        Settings::flush();
        Settings::set('kill_switch_engaged', false);
        Settings::set('sending_enabled', true);
        Settings::set('tracking_base_url', 'https://crm.test');
        Settings::set('alert_min_sample_size', 1);

        Mailer::setTransport(null);
    }

    protected function tearDown(): void
    {
        Mailer::setTransport(null);
        Settings::flush();
        parent::tearDown();
    }

    protected function truncateAll(): void
    {
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param array<string,mixed> $overrides */
    protected function makeContact(string $email, array $overrides = []): int
    {
        return ContactService::create(array_merge([
            'email'        => $email,
            'first_name'   => 'Test',
            'last_name'    => 'Person',
            'company_name' => 'Acme',
            'website'      => 'https://acme.test',
            'country'      => 'United Kingdom',
            'source'       => 'phpunit',
        ], $overrides));
    }

    protected function makeUser(string $email = 'admin@techzapp.test', string $role = 'admin'): int
    {
        Database::execute(
            'INSERT INTO users (email, normalized_email, password_hash, name, role, is_active)
             VALUES (:email, :normalized, :hash, :name, :role, 1)',
            [
                'email'      => $email,
                'normalized' => strtolower($email),
                'hash'       => \Techzapp\Crm\Core\Auth::hashPassword('CorrectHorse9Battery'),
                'name'       => 'Test User',
                'role'       => $role,
            ]
        );
        return Database::lastInsertId();
    }

    /** @param array<string,mixed> $overrides */
    protected function makeCampaign(int $listId, array $overrides = []): int
    {
        return \Techzapp\Crm\Services\CampaignService::create(array_merge([
            'name'         => 'Test campaign',
            'list_id'      => $listId,
            'from_name'    => 'Techzapp',
            'from_email'   => 'sender@example.com',
            'reply_to'     => 'replies@example.com',
            'subject'      => 'Hello {{first_name}}',
            'preview_text' => 'Preview',
            'html_body'    => '<p>Hi {{first_name}}, see <a href="https://techzapp.test/offer">the offer</a>.</p>',
            'track_opens'  => true,
            'track_clicks' => true,
        ], $overrides), null);
    }

    /** Runs a worker script in a separate PHP process (for concurrency tests). */
    protected function runWorker(string $script, array $arguments = []): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off ' . escapeshellarg($script);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg((string) $argument);
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $this->workerEnvironment());

        if (!is_resource($process)) {
            $this->fail('Unable to start worker process.');
        }

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @param array{process:resource, pipes:array} $worker */
    protected function waitForWorker(array $worker): array
    {
        $stdout = (string) stream_get_contents($worker['pipes'][1]);
        $stderr = (string) stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);

        return ['stdout' => trim($stdout), 'stderr' => trim($stderr), 'exit_code' => $exitCode];
    }

    /** @return array<string,string> */
    protected function workerEnvironment(): array
    {
        $environment = [
            'TEST_DB_HOST'     => (string) (getenv('TEST_DB_HOST') ?: '127.0.0.1'),
            'TEST_DB_PORT'     => (string) (getenv('TEST_DB_PORT') ?: '3306'),
            'TEST_DB_NAME'     => (string) getenv('TEST_DB_NAME'),
            'TEST_DB_USER'     => (string) (getenv('TEST_DB_USER') ?: 'root'),
            'TEST_DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'PATH'             => (string) getenv('PATH'),
            'SystemRoot'       => (string) getenv('SystemRoot'),
        ];
        return array_filter($environment, static fn (string $value): bool => $value !== '' || true);
    }
}
