<?php

declare(strict_types=1);

namespace Techzapp\Crm\Services;

use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Logger;
use Techzapp\Crm\Core\Settings;
use Throwable;

/**
 * Email event ingestion and idempotent recording.
 *
 * Provider notifications are written to provider_notifications first and parsed
 * by cron. Each resulting event carries a dedupe key with a UNIQUE index, so a
 * redelivered SNS message — which Amazon explicitly allows — cannot inflate any
 * report.
 */
final class EventService
{
    public const TYPES = [
        'send', 'delivery', 'bounce', 'complaint', 'reject', 'rendering_failure',
        'delivery_delay', 'open', 'click', 'unsubscribe', 'subscription', 'failure',
    ];

    /** Records one event. Returns false when it was already recorded. */
    public static function record(
        ?int $campaignId,
        ?int $recipientId,
        ?int $contactId,
        string $eventType,
        string $dedupeKey,
        ?string $providerEventId,
        string $timestamp,
        array $metadata = [],
        string $subtype = ''
    ): bool {
        if (!in_array($eventType, self::TYPES, true)) {
            return false;
        }

        try {
            $affected = Database::execute(
                'INSERT IGNORE INTO email_events
                    (campaign_id, recipient_id, contact_id, provider_event_id, dedupe_key,
                     event_type, event_subtype, event_timestamp, metadata_json)
                 VALUES (:campaign, :recipient, :contact, :provider, :dedupe, :type, :subtype, :ts, :meta)',
                [
                    'campaign'  => $campaignId,
                    'recipient' => $recipientId,
                    'contact'   => $contactId,
                    'provider'  => $providerEventId,
                    'dedupe'    => $dedupeKey,
                    'type'      => $eventType,
                    'subtype'   => substr($subtype, 0, 80),
                    'ts'        => $timestamp,
                    'meta'      => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
                ]
            );
            return $affected > 0;
        } catch (Throwable $e) {
            Logger::exception('events.record_failed', $e, ['type' => $eventType, 'dedupe' => $dedupeKey]);
            return false;
        }
    }

    /** Stores a raw provider notification for asynchronous processing. */
    public static function queueNotification(string $messageId, string $topicArn, string $payload, string $source = 'ses_sns'): bool
    {
        $affected = Database::execute(
            'INSERT IGNORE INTO provider_notifications (source, message_id, topic_arn, payload_json)
             VALUES (:source, :message_id, :topic, :payload)',
            [
                'source'     => $source,
                'message_id' => substr($messageId, 0, 255),
                'topic'      => substr($topicArn, 0, 255),
                'payload'    => $payload,
            ]
        );

        return $affected > 0;
    }

    /**
     * Parses queued notifications into email_events and recipient state.
     *
     * @return array{processed:int, failed:int, ignored:int}
     */
    public static function processPending(int $limit = 200, ?float $deadline = null): array
    {
        $rows = Database::all(
            "SELECT id, message_id, payload_json, attempts
               FROM provider_notifications
              WHERE status = 'pending' AND attempts < 5
           ORDER BY id
              LIMIT " . max(1, min(1000, $limit))
        );

        $processed = $failed = $ignored = 0;

        foreach ($rows as $row) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            $id = (int) $row['id'];
            try {
                $outcome = self::applyNotification((string) $row['message_id'], (string) $row['payload_json']);

                Database::execute(
                    'UPDATE provider_notifications
                        SET status = :status, processed_at = UTC_TIMESTAMP(), attempts = attempts + 1, last_error = ""
                      WHERE id = :id',
                    ['status' => $outcome ? 'processed' : 'ignored', 'id' => $id]
                );

                $outcome ? $processed++ : $ignored++;
            } catch (Throwable $e) {
                $failed++;
                $attempts = (int) $row['attempts'] + 1;
                Database::execute(
                    'UPDATE provider_notifications
                        SET status = :status, attempts = :attempts, last_error = :error
                      WHERE id = :id',
                    [
                        'status'   => $attempts >= 5 ? 'failed' : 'pending',
                        'attempts' => $attempts,
                        'error'    => substr($e->getMessage(), 0, 500),
                        'id'       => $id,
                    ]
                );
                Logger::exception('events.notification_failed', $e, ['notification_id' => $id]);
            }
        }

        return ['processed' => $processed, 'failed' => $failed, 'ignored' => $ignored];
    }

    /** Returns true when the payload produced a recognised event. */
    public static function applyNotification(string $snsMessageId, string $payload): bool
    {
        $envelope = json_decode($payload, true);
        if (!is_array($envelope)) {
            return false;
        }

        // SNS wraps the SES event JSON in a "Message" string field.
        $event = $envelope;
        if (isset($envelope['Message']) && is_string($envelope['Message'])) {
            $decoded = json_decode($envelope['Message'], true);
            if (is_array($decoded)) {
                $event = $decoded;
            }
        }

        $rawType = (string) ($event['eventType'] ?? $event['notificationType'] ?? '');
        $type = self::normaliseType($rawType);
        if ($type === null) {
            return false;
        }

        $mail = is_array($event['mail'] ?? null) ? $event['mail'] : [];
        $sesMessageId = (string) ($mail['messageId'] ?? '');
        $tags = self::flattenTags($mail['tags'] ?? []);

        $recipient = self::locateRecipient($sesMessageId, $tags, $event, $type);
        if ($recipient === null) {
            // Events for messages this system did not send (or sent before a
            // database restore) are recorded without a recipient link rather
            // than discarded, so they still appear in deliverability counts.
            Logger::info('events.recipient_not_found', [
                'ses_message_id' => $sesMessageId,
                'type'           => $type,
            ]);
        }

        $timestamp = self::extractTimestamp($event, $type);
        $subtype = self::extractSubtype($event, $type);
        $dedupe = $snsMessageId !== ''
            ? hash('sha256', 'sns|' . $snsMessageId)
            : hash('sha256', implode('|', [$type, $sesMessageId, $timestamp, $subtype]));

        $inserted = self::record(
            campaignId: $recipient === null ? self::campaignIdFromTags($tags) : (int) $recipient['campaign_id'],
            recipientId: $recipient === null ? null : (int) $recipient['id'],
            contactId: $recipient === null || $recipient['contact_id'] === null ? null : (int) $recipient['contact_id'],
            eventType: $type,
            dedupeKey: $dedupe,
            providerEventId: $snsMessageId !== '' ? $snsMessageId : null,
            timestamp: $timestamp,
            metadata: self::extractMetadata($event, $type),
            subtype: $subtype
        );

        // State changes are applied only once, on first insert of the event.
        if ($inserted && $recipient !== null) {
            self::applyRecipientState($recipient, $type, $subtype, $event, $timestamp);
        }

        return true;
    }

    /** @param array<string,mixed> $recipient */
    private static function applyRecipientState(array $recipient, string $type, string $subtype, array $event, string $timestamp): void
    {
        $recipientId = (int) $recipient['id'];
        $email = (string) $recipient['recipient_email_snapshot'];
        $campaignId = (int) $recipient['campaign_id'];

        switch ($type) {
            case 'delivery':
                // Only advance from accepted: a later delivery event must not
                // overwrite a bounce or complaint that already arrived.
                Database::execute(
                    "UPDATE campaign_recipients
                        SET status = 'delivered', delivered_at = COALESCE(delivered_at, :ts)
                      WHERE id = :id AND status IN ('accepted','sending','needs_reconciliation')",
                    ['ts' => $timestamp, 'id' => $recipientId]
                );
                break;

            case 'bounce':
                $permanent = str_contains(strtolower($subtype), 'permanent');
                Database::execute(
                    "UPDATE campaign_recipients
                        SET status = 'bounced', bounced_at = COALESCE(bounced_at, :ts),
                            last_error_code = :code,
                            last_error_message = :message
                      WHERE id = :id AND status NOT IN ('complained')",
                    [
                        'ts'      => $timestamp,
                        'code'    => substr($subtype, 0, 100),
                        'message' => substr(self::firstDiagnostic($event), 0, 500),
                        'id'      => $recipientId,
                    ]
                );
                if ($permanent) {
                    SuppressionService::suppress($email, 'hard_bounce', 'ses_event', $campaignId, $subtype);
                } else {
                    self::handleSoftBounce($email, $campaignId, $subtype);
                }
                break;

            case 'complaint':
                Database::execute(
                    "UPDATE campaign_recipients
                        SET status = 'complained', complained_at = COALESCE(complained_at, :ts),
                            last_error_code = :code
                      WHERE id = :id",
                    ['ts' => $timestamp, 'code' => substr($subtype, 0, 100), 'id' => $recipientId]
                );
                SuppressionService::suppress($email, 'complaint', 'ses_event', $campaignId, $subtype);
                break;

            case 'reject':
            case 'rendering_failure':
                Database::execute(
                    "UPDATE campaign_recipients
                        SET status = 'failed', last_error_code = :code, last_error_message = :message
                      WHERE id = :id AND status IN ('accepted','sending','needs_reconciliation','pending')",
                    [
                        'code'    => $type,
                        'message' => substr((string) ($event['reject']['reason'] ?? $event['failure']['errorMessage'] ?? $subtype), 0, 500),
                        'id'      => $recipientId,
                    ]
                );
                break;

            case 'open':
                Database::execute(
                    'UPDATE campaign_recipients SET first_opened_at = COALESCE(first_opened_at, :ts) WHERE id = :id',
                    ['ts' => $timestamp, 'id' => $recipientId]
                );
                break;

            case 'click':
                // Distinct placeholder names: PDO with emulation disabled
                // rejects the same named parameter used twice.
                Database::execute(
                    'UPDATE campaign_recipients
                        SET first_clicked_at = COALESCE(first_clicked_at, :clicked_at),
                            first_opened_at = COALESCE(first_opened_at, :opened_at)
                      WHERE id = :id',
                    ['clicked_at' => $timestamp, 'opened_at' => $timestamp, 'id' => $recipientId]
                );
                break;

            case 'unsubscribe':
                SuppressionService::suppress($email, 'unsubscribe', 'ses_event', $campaignId, 'List-Unsubscribe via provider');
                Database::execute(
                    'UPDATE campaign_recipients SET unsubscribed_at = COALESCE(unsubscribed_at, :ts) WHERE id = :id',
                    ['ts' => $timestamp, 'id' => $recipientId]
                );
                break;

            case 'delivery_delay':
            case 'send':
            case 'subscription':
            case 'failure':
            default:
                break;
        }
    }

    /**
     * A soft bounce is not immediately fatal, but an address that keeps soft
     * bouncing across campaigns is. Count distinct soft bounces and suppress
     * once the configured threshold is reached.
     */
    private static function handleSoftBounce(string $email, int $campaignId, string $subtype): void
    {
        $threshold = Settings::int('soft_bounce_max_attempts', 3);
        if ($threshold <= 0) {
            return;
        }

        $count = (int) Database::scalar(
            "SELECT COUNT(*)
               FROM email_events e
               JOIN campaign_recipients r ON r.id = e.recipient_id
              WHERE e.event_type = 'bounce'
                AND e.event_subtype LIKE 'Transient%'
                AND r.recipient_email_snapshot = :email",
            ['email' => $email]
        );

        if ($count >= $threshold) {
            SuppressionService::suppress(
                $email,
                'soft_bounce_threshold',
                'ses_event',
                $campaignId,
                "Soft bounced {$count} times ({$subtype})"
            );
        }
    }

    /** @return array<string,mixed>|null */
    private static function locateRecipient(string $sesMessageId, array $tags, array $event, string $type): ?array
    {
        $recipientId = isset($tags['recipient_id']) && ctype_digit((string) $tags['recipient_id'])
            ? (int) $tags['recipient_id']
            : null;

        if ($recipientId !== null) {
            $row = Database::first(
                'SELECT id, campaign_id, contact_id, recipient_email_snapshot, status
                   FROM campaign_recipients WHERE id = :id',
                ['id' => $recipientId]
            );
            if ($row !== null) {
                return $row;
            }
        }

        if ($sesMessageId !== '') {
            $row = Database::first(
                'SELECT id, campaign_id, contact_id, recipient_email_snapshot, status
                   FROM campaign_recipients WHERE ses_message_id = :mid
               ORDER BY id DESC LIMIT 1',
                ['mid' => $sesMessageId]
            );
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    private static function campaignIdFromTags(array $tags): ?int
    {
        $value = $tags['campaign_id'] ?? null;
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        $id = (int) $value;
        return Database::scalar('SELECT id FROM campaigns WHERE id = :id', ['id' => $id]) === null ? null : $id;
    }

    /** SES tag values arrive as arrays: {"campaign_id":["12"]}. */
    private static function flattenTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }
        $flat = [];
        foreach ($tags as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_scalar($value)) {
                $flat[$key] = (string) $value;
            }
        }
        return $flat;
    }

    public static function normaliseType(string $rawType): ?string
    {
        return match (strtolower(str_replace([' ', '-'], '', $rawType))) {
            'send'             => 'send',
            'delivery'         => 'delivery',
            'bounce'           => 'bounce',
            'complaint'        => 'complaint',
            'reject'           => 'reject',
            'renderingfailure' => 'rendering_failure',
            'deliverydelay'    => 'delivery_delay',
            'open'             => 'open',
            'click'            => 'click',
            'subscription'     => 'subscription',
            'failure'          => 'failure',
            default            => null,
        };
    }

    private static function extractTimestamp(array $event, string $type): string
    {
        $candidates = [
            $event[self::sectionKey($type)]['timestamp'] ?? null,
            $event['mail']['timestamp'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $time = strtotime($candidate);
            if ($time !== false) {
                return gmdate('Y-m-d H:i:s', $time);
            }
        }

        return Database::nowUtc();
    }

    private static function extractSubtype(array $event, string $type): string
    {
        return match ($type) {
            'bounce'         => trim(((string) ($event['bounce']['bounceType'] ?? '')) . '/' . ((string) ($event['bounce']['bounceSubType'] ?? '')), '/'),
            'complaint'      => (string) ($event['complaint']['complaintFeedbackType'] ?? 'unspecified'),
            'delivery_delay' => (string) ($event['deliveryDelay']['delayType'] ?? ''),
            'reject'         => (string) ($event['reject']['reason'] ?? ''),
            default          => '',
        };
    }

    private static function extractMetadata(array $event, string $type): array
    {
        $section = self::sectionKey($type);
        $data = is_array($event[$section] ?? null) ? $event[$section] : [];

        $metadata = ['source' => 'ses'];
        foreach (['smtpResponse', 'reportingMTA', 'ipAddress', 'userAgent', 'link', 'delayType', 'reason', 'errorMessage'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $metadata[$key] = substr((string) $data[$key], 0, 500);
            }
        }
        if ($type === 'bounce') {
            $metadata['diagnostic'] = substr(self::firstDiagnostic($event), 0, 500);
        }

        return $metadata;
    }

    private static function sectionKey(string $type): string
    {
        return match ($type) {
            'delivery'         => 'delivery',
            'bounce'           => 'bounce',
            'complaint'        => 'complaint',
            'reject'           => 'reject',
            'open'             => 'open',
            'click'            => 'click',
            'delivery_delay'   => 'deliveryDelay',
            'rendering_failure' => 'failure',
            'send'             => 'send',
            default            => $type,
        };
    }

    private static function firstDiagnostic(array $event): string
    {
        $recipients = $event['bounce']['bouncedRecipients'] ?? [];
        if (is_array($recipients) && isset($recipients[0]) && is_array($recipients[0])) {
            return (string) ($recipients[0]['diagnosticCode'] ?? $recipients[0]['status'] ?? '');
        }
        return '';
    }
}
