<?php

declare(strict_types=1);

namespace TechzappCrm\Services;

use TechzappCrm\Core\Database;
use TechzappCrm\Support\EmailAddress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Global suppression list.
 *
 * Suppression is keyed on the normalized email address, not the contact id, so
 * it survives contact deletion, re-import and list changes. Re-importing an
 * address that previously complained or opted out never resurrects it.
 */
final class SuppressionService
{
    public const REASONS = [
        'unsubscribe', 'complaint', 'hard_bounce', 'manual_block',
        'invalid_address', 'soft_bounce_threshold',
    ];

    /**
     * Adds (or reaffirms) a suppression. Returns true when newly suppressed.
     *
     * The strongest reason wins: an address suppressed for a complaint is not
     * downgraded to "unsubscribe" by a later event.
     */
    public static function suppress(
        string $email,
        string $reason,
        string $source = 'system',
        ?int $campaignId = null,
        string $notes = '',
        ?int $actorUserId = null
    ): bool {
        $normalized = EmailAddress::normalize($email);
        if ($normalized === '' || !in_array($reason, self::REASONS, true)) {
            return false;
        }

        $existing = Database::first(
            'SELECT id, reason FROM {suppressions} WHERE normalized_email = :email',
            ['email' => $normalized]
        );

        if ($existing !== null) {
            if (self::severity($reason) > self::severity((string) $existing['reason'])) {
                Database::execute(
                    'UPDATE {suppressions}
                        SET reason = :reason, source = :source, notes = :notes, updated_at = :updated_at
                      WHERE id = :id',
                    [
                        'reason'     => $reason,
                        'source'     => $source,
                        'notes'      => substr($notes, 0, 500),
                        'updated_at' => Database::nowUtc(),
                        'id'         => (int) $existing['id'],
                    ]
                );
            }
            self::recordEvent($normalized, 'reaffirmed', $reason, $source, $actorUserId, $notes);
            return false;
        }

        Database::execute(
            'INSERT INTO {suppressions} (normalized_email, reason, source, campaign_id, notes, created_at, updated_at)
             VALUES (:email, :reason, :source, :campaign_id, :notes, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
            [
                'email'       => $normalized,
                'reason'      => $reason,
                'source'      => substr($source, 0, 120),
                'campaign_id' => $campaignId,
                'notes'       => substr($notes, 0, 500),
                'created_at'  => Database::nowUtc(),
                'updated_at'  => Database::nowUtc(),
            ]
        );

        self::recordEvent($normalized, 'added', $reason, $source, $actorUserId, $notes);

        // Keep the contact record but mark its opt-out state.
        if ($reason === 'unsubscribe' || $reason === 'complaint') {
            Database::execute(
                'UPDATE {contacts} SET unsubscribed_at = COALESCE(unsubscribed_at, UTC_TIMESTAMP())
                  WHERE normalized_email = :email',
                ['email' => $normalized]
            );
        }

        return true;
    }

    /** Removes a suppression. Only an explicit administrative action does this. */
    public static function release(string $email, ?int $actorUserId, string $notes = ''): bool
    {
        $normalized = EmailAddress::normalize($email);
        $deleted = Database::execute(
            'DELETE FROM {suppressions} WHERE normalized_email = :email',
            ['email' => $normalized]
        );

        if ($deleted > 0) {
            self::recordEvent($normalized, 'removed', '', 'admin', $actorUserId, $notes);
            Database::execute(
                'UPDATE {contacts} SET unsubscribed_at = NULL WHERE normalized_email = :email',
                ['email' => $normalized]
            );
        }

        return $deleted > 0;
    }

    public static function isSuppressed(string $email): bool
    {
        return Database::scalar(
            'SELECT 1 FROM {suppressions} WHERE normalized_email = :email',
            ['email' => EmailAddress::normalize($email)]
        ) !== null;
    }

    /**
     * @param list<string> $emails
     * @return array<string,string> normalized email => reason, for suppressed addresses only
     */
    public static function filterSuppressed(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $normalized = array_values(array_unique(array_map(
            static fn (string $email): string => EmailAddress::normalize($email),
            $emails
        )));

        $found = [];
        // Chunked to stay well under max_prepared_stmt_count / placeholder limits.
        foreach (array_chunk($normalized, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Database::all(
                "SELECT normalized_email, reason FROM {suppressions} WHERE normalized_email IN ({$placeholders})",
                $chunk
            );
            foreach ($rows as $row) {
                $found[(string) $row['normalized_email']] = (string) $row['reason'];
            }
        }

        return $found;
    }

    /** @return list<array<string,mixed>> */
    public static function history(string $email, int $limit = 50): array
    {
        return Database::all(
            'SELECT se.id, se.action, se.reason, se.source, se.notes, se.created_at, u.email AS actor_email
               FROM {suppression_events} se
          LEFT JOIN users u ON u.id = se.actor_user_id
              WHERE se.normalized_email = :email
           ORDER BY se.created_at DESC, se.id DESC
              LIMIT ' . max(1, min(200, $limit)),
            ['email' => EmailAddress::normalize($email)]
        );
    }

    private static function recordEvent(
        string $normalizedEmail,
        string $action,
        string $reason,
        string $source,
        ?int $actorUserId,
        string $notes
    ): void {
        Database::execute(
            'INSERT INTO {suppression_events} (normalized_email, action, reason, source, actor_user_id, notes, created_at)
             VALUES (:email, :action, :reason, :source, :actor, :notes, :created_at)',
            [
                'email'  => $normalizedEmail,
                'action' => $action,
                'reason' => substr($reason, 0, 60),
                'source' => substr($source, 0, 120),
                'actor'  => $actorUserId,
                'notes'  => substr($notes, 0, 500),
                'created_at' => Database::nowUtc(),
            ]
        );
    }

    private static function severity(string $reason): int
    {
        return match ($reason) {
            'complaint'             => 5,
            'hard_bounce'           => 4,
            'manual_block'          => 3,
            'unsubscribe'           => 2,
            'invalid_address'       => 2,
            'soft_bounce_threshold' => 1,
            default                 => 0,
        };
    }
}
