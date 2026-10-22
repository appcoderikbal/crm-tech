<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/** Append-only record of administrative and sending actions. */
final class Audit
{
    public static function log(
        string $action,
        string $entityType = '',
        string|int $entityId = '',
        array $details = [],
        ?int $userId = null,
        string $actorLabel = ''
    ): void {
        try {
            $userId ??= Capabilities::currentUserId();

            if ($actorLabel === '') {
                if ($userId !== null) {
                    $user = get_userdata($userId);
                    $actorLabel = $user instanceof \WP_User ? $user->user_login : 'user ' . $userId;
                } else {
                    $actorLabel = (defined('WP_CLI') && WP_CLI) ? 'wp-cli' : 'cron';
                }
            }

            Database::execute(
                'INSERT INTO {audit_logs}
                    (user_id, actor_label, action, entity_type, entity_id, ip_address, user_agent, details_json, created_at)
                 VALUES (:user_id, :actor, :action, :entity_type, :entity_id, :ip, :ua, :details, :created_at)',
                [
                    'user_id'     => $userId,
                    'actor'       => substr($actorLabel, 0, 120),
                    'action'      => substr($action, 0, 100),
                    'entity_type' => substr($entityType, 0, 60),
                    'entity_id'   => substr((string) $entityId, 0, 60),
                    'ip'          => self::clientIp(),
                    'ua'          => substr(self::userAgent(), 0, 255),
                    'details'     => $details === [] ? null : wp_json_encode($details, JSON_UNESCAPED_SLASHES),
                    'created_at'  => Database::nowUtc(),
                ]
            );
        } catch (Throwable $e) {
            // Auditing must never break the operation it records.
            Logger::exception('audit.write_failed', $e, ['action' => $action]);
        }
    }

    /**
     * The client address.
     *
     * Stored as text rather than packed binary: WordPress hosts sit behind a
     * mix of proxies and the admin screen has to render it anyway.
     */
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $raw = sanitize_text_field(wp_unslash((string) $_SERVER[$key]));
            $candidate = trim(explode(',', $raw)[0]);

            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return '';
    }

    private static function userAgent(): string
    {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT']))
            : '';
    }

    /**
     * @return array{items:list<array<string,mixed>>, total:int, pages:int}
     */
    public static function paginate(string $actionFilter, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = '1 = 1';
        $params = [];

        if (trim($actionFilter) !== '') {
            $where = 'action LIKE :action';
            $params['action'] = str_replace(['%', '_'], ['\%', '\_'], trim($actionFilter)) . '%';
        }

        $total = (int) Database::scalar("SELECT COUNT(*) FROM {audit_logs} WHERE {$where}", $params);

        $items = Database::all(
            "SELECT id, user_id, actor_label, action, entity_type, entity_id,
                    ip_address, details_json, created_at
               FROM {audit_logs}
              WHERE {$where}
           ORDER BY id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items' => $items,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }
}
