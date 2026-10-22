<?php

declare(strict_types=1);

namespace Techzapp\Crm\Core;

use Throwable;

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
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $packed = is_string($ip) ? @inet_pton($ip) : false;

            Database::execute(
                'INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, ip_address, user_agent, details_json)
                 VALUES (:user_id, :actor, :action, :entity_type, :entity_id, :ip, :ua, :details)',
                [
                    'user_id'     => $userId,
                    'actor'       => substr($actorLabel, 0, 120),
                    'action'      => substr($action, 0, 100),
                    'entity_type' => substr($entityType, 0, 60),
                    'entity_id'   => substr((string) $entityId, 0, 60),
                    'ip'          => $packed === false ? null : $packed,
                    'ua'          => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (Throwable $e) {
            // Auditing must never break the operation it records.
            Logger::exception('audit.write_failed', $e, ['action' => $action]);
        }
    }
}
