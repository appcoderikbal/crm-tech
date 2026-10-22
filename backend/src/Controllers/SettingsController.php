<?php

declare(strict_types=1);

namespace Techzapp\Crm\Controllers;

use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Core\Config;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Settings;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;
use Techzapp\Crm\Services\AlertService;
use Techzapp\Crm\Services\Mail\Mailer;
use Techzapp\Crm\Services\TrackingService;

final class SettingsController
{
    public function show(Request $request): Response
    {
        Auth::requireUser();
        $config = Config::load();

        return Response::json([
            'settings' => Settings::allTyped(),
            'editable' => Settings::EDITABLE,
            // Non-secret provider facts only. Keys are never exposed, not even
            // partially, and never reach the frontend bundle.
            'provider' => [
                'transport'            => Mailer::transport()->name(),
                'region'               => $config->string('ses.region'),
                'configuration_set'    => $config->string('ses.configuration_set'),
                'verified_identities'  => $config->array('ses.verified_identities'),
                'credentials_configured' => $config->string('ses.access_key_id') !== ''
                    || getenv('AWS_ACCESS_KEY_ID') !== false,
                'account'              => AlertService::providerSnapshot(),
            ],
            'tracking' => [
                'base_url'        => TrackingService::baseUrl(),
                'open_pixel_path' => '/t/o',
                'click_path'      => '/t/c',
                'unsubscribe_path' => '/u/',
                'sns_webhook'     => TrackingService::baseUrl() . '/webhooks/ses.php',
            ],
            'runtime' => [
                'php_version'         => PHP_VERSION,
                'memory_limit'        => ini_get('memory_limit'),
                'max_execution_time'  => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size'       => ini_get('post_max_size'),
                'database_version'    => (string) Database::scalar('SELECT VERSION()'),
            ],
            'cron' => Database::all('SELECT job_name, last_run_at, last_status, last_message, duration_ms, run_count FROM cron_heartbeats'),
            'health' => AlertService::health(),
        ]);
    }

    public function update(Request $request): Response
    {
        $user = Auth::requireAdmin();

        $payload = $request->body();
        if ($payload === []) {
            throw ApiException::validation(['settings' => 'Nothing to update.']);
        }

        $applied = [];
        $rejected = [];

        foreach ($payload as $key => $value) {
            if (!is_string($key) || !in_array($key, Settings::EDITABLE, true)) {
                $rejected[] = (string) $key;
                continue;
            }

            $error = $this->validateSetting($key, $value);
            if ($error !== null) {
                throw ApiException::validation([$key => $error]);
            }

            Settings::set($key, $value, (int) $user['id']);
            $applied[$key] = $key === 'kill_switch_reason' ? '[set]' : $value;
        }

        if ($applied !== []) {
            Audit::log('settings.updated', 'settings', '', $applied, (int) $user['id']);
        }

        return Response::json([
            'settings' => Settings::allTyped(),
            'applied'  => array_keys($applied),
            'rejected' => $rejected,
        ]);
    }

    /** Dedicated endpoint so the kill switch is one obvious, audited action. */
    public function killSwitch(Request $request): Response
    {
        $user = Auth::requireAdmin();

        $engage = filter_var($request->input('engage', true), FILTER_VALIDATE_BOOL);
        $reason = substr((string) $request->input('reason', ''), 0, 400);

        if ($engage && $reason === '') {
            throw ApiException::validation(['reason' => 'Explain why sending is being stopped.']);
        }

        Settings::set('kill_switch_engaged', $engage, (int) $user['id']);
        Settings::set(
            'kill_switch_reason',
            $engage ? $reason : '',
            (int) $user['id']
        );

        Audit::log(
            $engage ? 'sending.kill_switch_engaged' : 'sending.kill_switch_released',
            'system',
            'kill_switch',
            ['reason' => $reason],
            (int) $user['id']
        );

        if ($engage) {
            AlertService::raise(
                'kill_switch',
                'sending',
                'critical',
                'Sending is stopped',
                'The global kill switch is engaged: ' . $reason,
                ['engaged_by' => $user['email']]
            );
        } else {
            AlertService::resolve('kill_switch', 'released by ' . $user['email']);
            AlertService::resolve('auto_pause_complaints', 'released by ' . $user['email']);
        }

        return Response::json([
            'kill_switch_engaged' => $engage,
            'reason'              => $engage ? $reason : '',
            'sending_allowed'     => Settings::sendingAllowed(),
        ]);
    }

    public function audit(Request $request): Response
    {
        Auth::requireAdmin();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $offset = ($page - 1) * $perPage;

        $clauses = ['1 = 1'];
        $params = [];
        if (($action = (string) $request->query('action', '')) !== '') {
            $clauses[] = 'a.action LIKE :action';
            $params['action'] = str_replace(['%', '_'], ['\%', '\_'], $action) . '%';
        }

        $where = implode(' AND ', $clauses);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs a WHERE {$where}", $params);

        $items = Database::all(
            "SELECT a.id, a.action, a.entity_type, a.entity_id, a.details_json, a.created_at,
                    a.actor_label, u.email AS user_email,
                    INET6_NTOA(a.ip_address) AS ip_address
               FROM audit_logs a
          LEFT JOIN users u ON u.id = a.user_id
              WHERE {$where}
           ORDER BY a.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return Response::json($items, 200, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ]);
    }

    public function users(Request $request): Response
    {
        Auth::requireAdmin();

        return Response::json(Database::all(
            'SELECT id, email, name, role, timezone, is_active, last_login_at, created_at
               FROM users ORDER BY id'
        ));
    }

    private function validateSetting(string $key, mixed $value): ?string
    {
        $type = Settings::type($key);

        return match (true) {
            $type === 'int' && (!is_numeric($value) || (int) $value < 0) => 'Must be a positive whole number.',
            $type === 'float' && (!is_numeric($value) || (float) $value < 0) => 'Must be a positive number.',
            $key === 'default_timezone' && !in_array((string) $value, timezone_identifiers_list(), true) => 'Unknown timezone.',
            $key === 'tracking_base_url' && $value !== '' && !preg_match('#^https?://[^\s/]+#i', (string) $value) => 'Must be a full http(s) URL.',
            $key === 'max_send_rate_per_minute' && (int) $value > 10000 => 'That rate is higher than any SES account allows.',
            $key === 'test_send_allowed_domains' && !$this->isDomainList($value) => 'Provide a list of domains.',
            $key === 'personalization_fallbacks' && !$this->isFlatObject($value) => 'Provide an object of variable fallbacks.',
            default => null,
        };
    }

    private function isDomainList(mixed $value): bool
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $domain) {
            if (!is_string($domain) || preg_match('/^[a-z0-9.\-]{3,190}$/i', $domain) !== 1) {
                return false;
            }
        }
        return true;
    }

    private function isFlatObject(mixed $value): bool
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (!is_string($key) || (!is_scalar($item) && $item !== null)) {
                return false;
            }
        }
        return true;
    }
}
