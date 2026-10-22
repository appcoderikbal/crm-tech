<?php

declare(strict_types=1);

namespace TechzappCrm\Services;

use TechzappCrm\Core\Audit;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\Lock;
use TechzappCrm\Core\Logger;
use TechzappCrm\Core\Settings;
use TechzappCrm\Services\Mail\Mailer;
use TechzappCrm\Services\Mail\SendResult;
use TechzappCrm\Support\Tokens;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The sending pipeline. Everything here is designed to be interrupted.
 *
 * Idempotency rests on three things:
 *   1. UNIQUE(campaign_id, contact_id) — a recipient row can exist only once.
 *   2. An atomic claim (UPDATE … WHERE status='pending') — only one worker can
 *      ever move a given row out of the queue.
 *   3. Terminal statuses — a claimed row is never re-sent, only reconciled.
 *
 * A cron run that is killed halfway leaves rows in 'sending'; the reconciler
 * resolves them from recorded evidence instead of resending blindly.
 */
final class CampaignSender
{
    public const TERMINAL_STATUSES = ['accepted', 'delivered', 'bounced', 'complained', 'failed', 'suppressed', 'cancelled'];

    /**
     * Moves scheduled campaigns whose time has come into the queue.
     *
     * @return list<int> campaign ids queued by this call
     */
    public static function queueDueCampaigns(): array
    {
        $due = Database::all(
            "SELECT id FROM {campaigns}
              WHERE status = 'scheduled'
                AND scheduled_at_utc IS NOT NULL
                AND scheduled_at_utc <= UTC_TIMESTAMP()
           ORDER BY scheduled_at_utc
              LIMIT 50"
        );

        $queued = [];
        foreach ($due as $row) {
            $id = (int) $row['id'];
            // transition() guards on the source status, so a campaign cancelled
            // a moment ago is not picked up here.
            if (CampaignService::transition($id, 'queued', ['queued_at = UTC_TIMESTAMP()'])) {
                $queued[] = $id;
                Audit::log('campaign.auto_queued', 'campaign', $id, ['by' => 'cron']);
            }
        }

        return $queued;
    }

    /** @return list<int> campaigns with outstanding work, highest priority first */
    public static function campaignsNeedingWork(int $limit = 10): array
    {
        $rows = Database::all(
            "SELECT id FROM {campaigns}
              WHERE status IN ('queued','sending')
           ORDER BY COALESCE(started_at, queued_at, scheduled_at_utc), id
              LIMIT " . max(1, min(50, $limit))
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Creates the recipient rows for a campaign, in bounded batches.
     *
     * Safe to call repeatedly: INSERT IGNORE against UNIQUE(campaign_id,
     * contact_id) means an interrupted build simply continues where it stopped.
     *
     * @return array{inserted:int, skipped_suppressed:int, complete:bool}
     */
    public static function buildRecipients(int $campaignId, ?float $deadline = null): array
    {
        $campaign = Database::first(
            'SELECT id, list_id, status FROM {campaigns} WHERE id = :id',
            ['id' => $campaignId]
        );
        if ($campaign === null || $campaign['list_id'] === null) {
            return ['inserted' => 0, 'skipped_suppressed' => 0, 'complete' => true];
        }

        $batchSize = max(100, Settings::int('recipient_build_batch', 2000));
        $inserted = 0;
        $skipped = 0;
        $complete = false;

        while (true) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            // Candidates: list members with no recipient row yet.
            $candidates = Database::all(
                "SELECT c.id, c.email, c.normalized_email, c.first_name, c.last_name,
                        c.company_name, c.website, c.country, c.custom_fields_json,
                        s.id AS suppression_id, c.unsubscribed_at, c.status AS contact_status
                   FROM {list_contacts} lc
                   JOIN {contacts} c ON c.id = lc.contact_id
              LEFT JOIN {suppressions} s ON s.normalized_email = c.normalized_email
              LEFT JOIN {campaign_recipients} r ON r.campaign_id = :campaign AND r.contact_id = c.id
                  WHERE lc.list_id = :list_id AND r.id IS NULL
               ORDER BY c.id
                  LIMIT {$batchSize}",
                ['campaign' => $campaignId, 'list_id' => (int) $campaign['list_id']]
            );

            if ($candidates === []) {
                $complete = true;
                break;
            }

            $values = [];
            $params = [];
            $now = Database::nowUtc();

            foreach ($candidates as $contact) {
                $isSuppressed = $contact['suppression_id'] !== null
                    || $contact['unsubscribed_at'] !== null
                    || $contact['contact_status'] !== 'active';

                if ($isSuppressed) {
                    $skipped++;
                }

                $mergeData = wp_json_encode([
                    'first_name'   => (string) $contact['first_name'],
                    'last_name'    => (string) $contact['last_name'],
                    'company_name' => (string) $contact['company_name'],
                    'website'      => (string) $contact['website'],
                    'country'      => (string) $contact['country'],
                    'email'        => (string) $contact['email'],
                    'custom'       => $contact['custom_fields_json'],
                ], JSON_UNESCAPED_SLASHES);

                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    $campaignId,
                    (int) $contact['id'],
                    (string) $contact['email'],
                    $mergeData,
                    Tokens::tracking(),
                    $isSuppressed ? 'suppressed' : 'pending',
                    $now,
                    $now
                );
            }

            // INSERT IGNORE: a concurrent builder that already inserted a row
            // wins, and this run simply moves on.
            $affected = Database::execute(
                'INSERT IGNORE INTO {campaign_recipients}
                    (campaign_id, contact_id, recipient_email_snapshot, merge_data_json,
                     tracking_token, status, created_at, updated_at)
                 VALUES ' . implode(',', $values),
                $params
            );
            $inserted += $affected;

            if (count($candidates) < $batchSize) {
                $complete = true;
                break;
            }
        }

        if ($inserted > 0 || $complete) {
            Database::execute(
                'UPDATE {campaigns} SET total_recipients =
                    (SELECT COUNT(*) FROM {campaign_recipients} WHERE campaign_id = :id1)
                  WHERE id = :id2',
                ['id1' => $campaignId, 'id2' => $campaignId]
            );
        }

        return ['inserted' => $inserted, 'skipped_suppressed' => $skipped, 'complete' => $complete];
    }

    /**
     * Processes one campaign for at most $deadline seconds.
     *
     * @return array{campaign_id:int, sent:int, failed:int, suppressed:int, uncertain:int, status:string, reason:string}
     */
    public static function processCampaign(int $campaignId, float $deadline): array
    {
        $result = [
            'campaign_id' => $campaignId,
            'sent'        => 0,
            'failed'      => 0,
            'suppressed'  => 0,
            'uncertain'   => 0,
            'status'      => '',
            'reason'      => '',
        ];

        if (!Settings::sendingAllowed()) {
            $result['reason'] = 'sending_disabled';
            return $result;
        }

        // One worker per campaign. The lease is short so a killed worker frees
        // the campaign for the next cron run within a minute.
        $lock = Lock::acquire('campaign:' . $campaignId, 180, 'pid ' . getmypid());
        if ($lock === null) {
            $result['reason'] = 'locked_by_another_worker';
            return $result;
        }

        try {
            $campaign = Database::first('SELECT * FROM {campaigns} WHERE id = :id', ['id' => $campaignId]);
            if ($campaign === null) {
                $result['reason'] = 'not_found';
                return $result;
            }

            $result['status'] = (string) $campaign['status'];
            if (!in_array($campaign['status'], ['queued', 'sending'], true)) {
                $result['reason'] = 'status_' . $campaign['status'];
                return $result;
            }

            // Re-verify the sender every run: an identity can be revoked while
            // a campaign is mid-flight.
            $transport = Mailer::transport();
            if (!$transport->isIdentityUsable((string) $campaign['from_email'])) {
                self::failCampaign($campaignId, 'Sender identity is no longer verified in SES.');
                AlertService::raise(
                    'sender_identity_invalid:' . $campaignId,
                    'sender_identity',
                    'critical',
                    'Sender identity not verified',
                    "Campaign \"{$campaign['name']}\" was stopped because {$campaign['from_email']} is not a verified SES identity.",
                    ['campaign_id' => $campaignId]
                );
                $result['reason'] = 'sender_unverified';
                return $result;
            }

            $build = self::buildRecipients($campaignId, min($deadline, microtime(true) + 20));
            if (!$build['complete'] && $build['inserted'] === 0) {
                $result['reason'] = 'building_recipients';
                return $result;
            }

            CampaignService::transition($campaignId, 'sending', ['started_at = COALESCE(started_at, UTC_TIMESTAMP())']);

            $template = CampaignRenderer::buildTemplate($campaign);
            $perMinute = CampaignService::clampRate($campaign['send_rate_per_minute']);
            $maxThisRun = max(1, Settings::int('max_sends_per_cron_run', 400));
            $batchSize = max(1, min(100, $perMinute, $maxThisRun));

            while (microtime(true) < $deadline && ($result['sent'] + $result['failed'] + $result['suppressed']) < $maxThisRun) {
                if (!$lock->heartbeat()) {
                    $result['reason'] = 'lost_lock';
                    break;
                }
                if (!Settings::sendingAllowed()) {
                    $result['reason'] = 'kill_switch_engaged';
                    break;
                }

                // Respect the campaign rate and the account-wide daily cap.
                $allowance = self::sendAllowance($perMinute);
                if ($allowance <= 0) {
                    $result['reason'] = 'rate_limited';
                    break;
                }

                $claimed = self::claimBatch($campaignId, min($batchSize, $allowance));
                if ($claimed === []) {
                    break;
                }

                $batch = self::sendBatch($campaign, $template, $claimed, $transport);
                $result['sent'] += $batch['sent'];
                $result['failed'] += $batch['failed'];
                $result['suppressed'] += $batch['suppressed'];
                $result['uncertain'] += $batch['uncertain'];

                if ($batch['stop_reason'] !== '') {
                    $result['reason'] = $batch['stop_reason'];
                    break;
                }
            }

            ReportService::refreshCampaignStats($campaignId);
            self::completeIfFinished($campaignId);

            $result['status'] = (string) Database::scalar('SELECT status FROM {campaigns} WHERE id = :id', ['id' => $campaignId]);
            return $result;
        } catch (Throwable $e) {
            Logger::exception('sender.campaign_failed', $e, ['campaign_id' => $campaignId]);
            Database::execute(
                'UPDATE {campaigns} SET consecutive_failures = consecutive_failures + 1, last_error = :error WHERE id = :id',
                ['error' => substr($e->getMessage(), 0, 500), 'id' => $campaignId]
            );
            $result['reason'] = 'exception';
            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Atomically claims up to $limit pending recipients.
     *
     * The UPDATE…LIMIT is the only way a row leaves 'pending', so two cron
     * processes running at the same moment receive disjoint sets.
     *
     * @return list<array<string,mixed>>
     */
    public static function claimBatch(int $campaignId, int $limit): array
    {
        $claimToken = Tokens::tracking();

        $claimed = Database::execute(
            "UPDATE {campaign_recipients}
                SET status = 'sending', claim_token = :token, claimed_at = UTC_TIMESTAMP(), attempts = attempts + 1
              WHERE campaign_id = :campaign
                AND status = 'pending'
                AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
           ORDER BY id
              LIMIT " . max(1, $limit),
            ['token' => $claimToken, 'campaign' => $campaignId]
        );

        if ($claimed === 0) {
            return [];
        }

        return Database::all(
            'SELECT id, campaign_id, contact_id, recipient_email_snapshot, merge_data_json,
                    tracking_token, attempts
               FROM {campaign_recipients}
              WHERE claim_token = :token AND campaign_id = :campaign',
            ['token' => $claimToken, 'campaign' => $campaignId]
        );
    }

    /**
     * @param list<array<string,mixed>> $recipients
     * @return array{sent:int, failed:int, suppressed:int, uncertain:int, stop_reason:string}
     */
    private static function sendBatch(array $campaign, array $template, array $recipients, $transport): array
    {
        $outcome = ['sent' => 0, 'failed' => 0, 'suppressed' => 0, 'uncertain' => 0, 'stop_reason' => ''];
        $campaignId = (int) $campaign['id'];

        // Suppression is re-checked here, immediately before sending: a contact
        // may have unsubscribed after the queue was built.
        $emails = array_map(static fn (array $r): string => (string) $r['recipient_email_snapshot'], $recipients);
        $suppressed = SuppressionService::filterSuppressed($emails);

        $maxAttempts = max(1, Settings::int('soft_bounce_max_attempts', 3));

        foreach ($recipients as $recipient) {
            $recipientId = (int) $recipient['id'];
            $email = (string) $recipient['recipient_email_snapshot'];
            $normalized = \TechzappCrm\Support\EmailAddress::normalize($email);

            if (isset($suppressed[$normalized])) {
                Database::execute(
                    "UPDATE {campaign_recipients}
                        SET status = 'suppressed', claim_token = NULL, last_error_code = :code,
                            last_error_message = 'Address is on the suppression list.'
                      WHERE id = :id",
                    ['code' => substr($suppressed[$normalized], 0, 100), 'id' => $recipientId]
                );
                $outcome['suppressed']++;
                continue;
            }

            $contact = self::contactDataFor($recipient);
            $rendered = CampaignRenderer::renderMessage($campaign, $template, $recipient, $contact);
            $result = $transport->send($rendered['message']);

            self::applySendResult($campaignId, $recipientId, $result, (int) $recipient['attempts'], $maxAttempts);

            if ($result->accepted) {
                $outcome['sent']++;
                self::incrementSendCounters();
                EventService::record(
                    campaignId: $campaignId,
                    recipientId: $recipientId,
                    contactId: $recipient['contact_id'] === null ? null : (int) $recipient['contact_id'],
                    eventType: 'send',
                    dedupeKey: hash('sha256', 'local-send|' . $recipientId . '|' . $result->messageId),
                    providerEventId: $result->messageId,
                    timestamp: Database::nowUtc(),
                    metadata: ['transport' => $transport->name()]
                );
            } elseif ($result->uncertain) {
                $outcome['uncertain']++;
            } elseif ($result->accountLevelProblem) {
                // Stop the whole run: every subsequent send would fail the same
                // way, and some account problems (suspension) get worse if hammered.
                self::handleAccountProblem($campaignId, $result);
                $outcome['stop_reason'] = 'account_problem';
                break;
            } else {
                $outcome['failed']++;
            }
        }

        return $outcome;
    }

    private static function applySendResult(
        int $campaignId,
        int $recipientId,
        SendResult $result,
        int $attempts,
        int $maxAttempts
    ): void {
        if ($result->accepted) {
            Database::execute(
                "UPDATE {campaign_recipients}
                    SET status = 'accepted', ses_message_id = :mid, accepted_at = UTC_TIMESTAMP(),
                        claim_token = NULL, last_error_code = '', last_error_message = ''
                  WHERE id = :id",
                ['mid' => $result->messageId, 'id' => $recipientId]
            );
            return;
        }

        if ($result->uncertain) {
            // Do NOT retry: the message may already be on its way.
            Database::execute(
                "UPDATE {campaign_recipients}
                    SET status = 'needs_reconciliation', claim_token = NULL,
                        last_error_code = :code, last_error_message = :message
                  WHERE id = :id",
                ['code' => $result->errorCode, 'message' => $result->errorMessage, 'id' => $recipientId]
            );
            Logger::warning('sender.uncertain_outcome', [
                'recipient_id' => $recipientId,
                'code'         => $result->errorCode,
            ]);
            return;
        }

        if ($result->accountLevelProblem) {
            // Return the recipient to the queue untouched; the problem is not theirs.
            Database::execute(
                "UPDATE {campaign_recipients}
                    SET status = 'pending', claim_token = NULL, attempts = GREATEST(attempts - 1, 0),
                        last_error_code = :code, last_error_message = :message
                  WHERE id = :id",
                ['code' => $result->errorCode, 'message' => $result->errorMessage, 'id' => $recipientId]
            );
            return;
        }

        if ($result->retryable && $attempts < $maxAttempts) {
            // Exponential backoff: 1, 2, 4 … minutes, capped.
            $delaySeconds = min(900, 60 * (2 ** max(0, $attempts - 1)));
            Database::execute(
                "UPDATE {campaign_recipients}
                    SET status = 'pending', claim_token = NULL,
                        next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :delay SECOND),
                        last_error_code = :code, last_error_message = :message
                  WHERE id = :id",
                ['delay' => $delaySeconds, 'code' => $result->errorCode, 'message' => $result->errorMessage, 'id' => $recipientId]
            );
            return;
        }

        Database::execute(
            "UPDATE {campaign_recipients}
                SET status = 'failed', claim_token = NULL,
                    last_error_code = :code, last_error_message = :message
              WHERE id = :id",
            ['code' => $result->errorCode, 'message' => $result->errorMessage, 'id' => $recipientId]
        );

        EventService::record(
            campaignId: $campaignId,
            recipientId: $recipientId,
            contactId: null,
            eventType: 'failure',
            dedupeKey: hash('sha256', 'local-failure|' . $recipientId . '|' . $attempts),
            providerEventId: null,
            timestamp: Database::nowUtc(),
            metadata: ['code' => $result->errorCode, 'message' => $result->errorMessage],
            subtype: substr($result->errorCode, 0, 80)
        );
    }

    private static function handleAccountProblem(int $campaignId, SendResult $result): void
    {
        CampaignService::transition($campaignId, 'paused');
        Database::execute(
            'UPDATE {campaigns} SET last_error = :error, consecutive_failures = consecutive_failures + 1 WHERE id = :id',
            ['error' => substr($result->errorCode . ': ' . $result->errorMessage, 0, 500), 'id' => $campaignId]
        );

        AlertService::raise(
            'ses_account_problem',
            'provider',
            'critical',
            'Amazon SES rejected sending',
            $result->errorCode . ': ' . $result->errorMessage,
            ['campaign_id' => $campaignId, 'code' => $result->errorCode]
        );

        Audit::log('campaign.paused_account_problem', 'campaign', $campaignId, [
            'code'    => $result->errorCode,
            'message' => $result->errorMessage,
        ]);
    }

    /** Marks a campaign failed and stops further processing. */
    public static function failCampaign(int $campaignId, string $reason): void
    {
        CampaignService::transition($campaignId, 'failed', ['last_error = :error'], ['error' => substr($reason, 0, 500)]);
    }

    /**
     * Completes a campaign only when every recipient has reached a terminal
     * status. A second cron run finding nothing left to do cannot "restart" a
     * completed campaign, because transition() refuses completed → anything.
     */
    public static function completeIfFinished(int $campaignId): bool
    {
        $outstanding = (int) Database::scalar(
            "SELECT COUNT(*) FROM {campaign_recipients}
              WHERE campaign_id = :id AND status IN ('pending','sending','needs_reconciliation')",
            ['id' => $campaignId]
        );
        if ($outstanding > 0) {
            return false;
        }

        $build = Database::first(
            'SELECT c.list_id,
                    (SELECT COUNT(*) FROM {list_contacts} WHERE list_id = c.list_id) AS list_members,
                    (SELECT COUNT(*) FROM {campaign_recipients} WHERE campaign_id = c.id) AS recipients
               FROM {campaigns} c WHERE c.id = :id',
            ['id' => $campaignId]
        );
        // Recipient creation may still be unfinished; not complete yet.
        if ($build !== null && (int) $build['recipients'] < (int) $build['list_members']) {
            return false;
        }

        $completed = CampaignService::transition($campaignId, 'completed', ['completed_at = UTC_TIMESTAMP()']);
        if ($completed) {
            ReportService::refreshCampaignStats($campaignId);
            Audit::log('campaign.completed', 'campaign', $campaignId, []);
        }
        return $completed;
    }

    /**
     * Resolves recipients left in a non-terminal state by an interrupted run.
     *
     * @return array{requeued:int, reconciled:int, failed:int}
     */
    public static function reconcileStale(): array
    {
        $staleMinutes = max(1, Settings::int('reconcile_stale_minutes', 15));
        $outcome = ['requeued' => 0, 'reconciled' => 0, 'failed' => 0];

        // Rows claimed but never resolved: the worker died between the claim
        // and the send. No SES message id means nothing was accepted, so they
        // are safe to return to the queue.
        $outcome['requeued'] = Database::execute(
            "UPDATE {campaign_recipients}
                SET status = 'pending', claim_token = NULL, claimed_at = NULL
              WHERE status = 'sending'
                AND ses_message_id IS NULL
                AND claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)",
            ['minutes' => $staleMinutes]
        );

        // Rows that did receive a message id are already accepted.
        $outcome['reconciled'] = Database::execute(
            "UPDATE {campaign_recipients}
                SET status = 'accepted', claim_token = NULL,
                    accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP())
              WHERE status = 'sending'
                AND ses_message_id IS NOT NULL
                AND claimed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)",
            ['minutes' => $staleMinutes]
        );

        // Uncertain sends: if a provider event arrived for the recipient, the
        // message did go out; otherwise give up rather than risk a duplicate.
        $uncertain = Database::all(
            "SELECT id, campaign_id FROM {campaign_recipients}
              WHERE status = 'needs_reconciliation'
                AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :minutes MINUTE)
              LIMIT 500",
            ['minutes' => $staleMinutes]
        );

        foreach ($uncertain as $row) {
            $recipientId = (int) $row['id'];
            $hasEvent = Database::scalar(
                "SELECT 1 FROM {email_events}
                  WHERE recipient_id = :id AND event_type IN ('send','delivery','bounce','complaint')
                  LIMIT 1",
                ['id' => $recipientId]
            );

            if ($hasEvent !== null) {
                Database::execute(
                    "UPDATE {campaign_recipients}
                        SET status = 'accepted', accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP())
                      WHERE id = :id AND status = 'needs_reconciliation'",
                    ['id' => $recipientId]
                );
                $outcome['reconciled']++;
            } else {
                Database::execute(
                    "UPDATE {campaign_recipients}
                        SET status = 'failed',
                            last_error_message = 'Send outcome could not be confirmed; not retried to avoid a duplicate send.'
                      WHERE id = :id AND status = 'needs_reconciliation'",
                    ['id' => $recipientId]
                );
                $outcome['failed']++;
            }
        }

        return $outcome;
    }

    /** How many sends the configured limits still allow right now. */
    public static function sendAllowance(int $campaignPerMinute): int
    {
        $minuteKey = gmdate('YmdHi');
        $dayKey = gmdate('Ymd');

        $minuteSent = (int) (Database::scalar(
            "SELECT sent_count FROM {send_counters} WHERE bucket_type = 'minute' AND bucket_key = :key",
            ['key' => $minuteKey]
        ) ?? 0);
        $daySent = (int) (Database::scalar(
            "SELECT sent_count FROM {send_counters} WHERE bucket_type = 'day' AND bucket_key = :key",
            ['key' => $dayKey]
        ) ?? 0);

        $accountPerMinute = max(1, Settings::int('max_send_rate_per_minute', 120));
        $dailyCap = max(0, Settings::int('max_sends_per_day', 50000));

        $minuteAllowance = min($campaignPerMinute, $accountPerMinute) - $minuteSent;
        $dayAllowance = $dailyCap === 0 ? PHP_INT_MAX : $dailyCap - $daySent;

        return (int) max(0, min($minuteAllowance, $dayAllowance));
    }

    private static function incrementSendCounters(): void
    {
        Database::execute(
            "INSERT INTO {send_counters} (bucket_type, bucket_key, sent_count, updated_at)
             VALUES ('minute', :key, 1, :now)
             ON DUPLICATE KEY UPDATE sent_count = sent_count + 1, updated_at = VALUES(updated_at)",
            ['key' => gmdate('YmdHi'), 'now' => Database::nowUtc()]
        );
        Database::execute(
            "INSERT INTO {send_counters} (bucket_type, bucket_key, sent_count, updated_at)
             VALUES ('day', :key, 1, :now)
             ON DUPLICATE KEY UPDATE sent_count = sent_count + 1, updated_at = VALUES(updated_at)",
            ['key' => gmdate('Ymd'), 'now' => Database::nowUtc()]
        );
    }

    /** @param array<string,mixed> $recipient @return array<string,mixed> */
    private static function contactDataFor(array $recipient): array
    {
        $merge = json_decode((string) ($recipient['merge_data_json'] ?? ''), true);
        if (!is_array($merge)) {
            $merge = [];
        }

        return [
            'first_name'        => (string) ($merge['first_name'] ?? ''),
            'last_name'         => (string) ($merge['last_name'] ?? ''),
            'company_name'      => (string) ($merge['company_name'] ?? ''),
            'website'           => (string) ($merge['website'] ?? ''),
            'country'           => (string) ($merge['country'] ?? ''),
            'email'             => (string) ($merge['email'] ?? $recipient['recipient_email_snapshot']),
            'custom_fields_json' => $merge['custom'] ?? null,
        ];
    }
}
