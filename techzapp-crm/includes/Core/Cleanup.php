<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Referential cleanup that foreign keys used to do.
 *
 * dbDelta cannot manage FOREIGN KEY constraints, so the schema has none and
 * the ON DELETE behaviour is reproduced here. Every delete path in the plugin
 * routes through these methods rather than issuing a bare DELETE, and each is
 * wrapped in a transaction so a half-finished cascade cannot be observed.
 *
 * The UNIQUE constraints — the ones that actually guarantee correctness — are
 * still enforced by the database itself.
 */
final class Cleanup
{
    /** Deletes a campaign and everything that belongs to it. */
    public static function deleteCampaign(int $campaignId): void
    {
        Database::transaction(static function () use ($campaignId): void {
            Database::execute('DELETE FROM {email_events} WHERE campaign_id = :id', ['id' => $campaignId]);
            Database::execute('DELETE FROM {campaign_recipients} WHERE campaign_id = :id', ['id' => $campaignId]);
            Database::execute('DELETE FROM {campaign_links} WHERE campaign_id = :id', ['id' => $campaignId]);
            Database::execute('DELETE FROM {campaign_stats} WHERE campaign_id = :id', ['id' => $campaignId]);
            Database::execute('DELETE FROM {alerts} WHERE campaign_id = :id', ['id' => $campaignId]);
            Database::execute('DELETE FROM {campaigns} WHERE id = :id', ['id' => $campaignId]);
        });
    }

    /**
     * Deletes contacts, releasing their list membership.
     *
     * Campaign history is kept: recipient rows lose contact_id but retain the
     * email snapshot and outcome, so past reports stay accurate. Suppression
     * also survives, because it keys on the address rather than the contact.
     *
     * @param list<int> $contactIds
     */
    public static function deleteContacts(array $contactIds): int
    {
        if ($contactIds === []) {
            return 0;
        }

        return Database::transaction(static function () use ($contactIds): int {
            $deleted = 0;

            foreach (array_chunk($contactIds, 500) as $chunk) {
                $in = self::inClause($chunk);

                Database::execute("DELETE FROM {list_contacts} WHERE contact_id IN ({$in})");
                Database::execute("UPDATE {campaign_recipients} SET contact_id = NULL WHERE contact_id IN ({$in})");
                Database::execute("UPDATE {email_events} SET contact_id = NULL WHERE contact_id IN ({$in})");
                $deleted += Database::execute("DELETE FROM {contacts} WHERE id IN ({$in})");
            }

            return $deleted;
        });
    }

    /**
     * Deletes a list and releases its members back to the unassigned pool.
     * Contacts are never deleted by this.
     */
    public static function deleteList(int $listId): int
    {
        return Database::transaction(static function () use ($listId): int {
            $released = Database::execute('DELETE FROM {list_contacts} WHERE list_id = :id', ['id' => $listId]);
            Database::execute('DELETE FROM {lists} WHERE id = :id', ['id' => $listId]);

            return $released;
        });
    }

    /** Deletes an import job and its error rows. */
    public static function deleteImportJob(int $jobId): void
    {
        Database::transaction(static function () use ($jobId): void {
            Database::execute('DELETE FROM {import_row_errors} WHERE import_job_id = :id', ['id' => $jobId]);
            Database::execute('DELETE FROM {import_jobs} WHERE id = :id', ['id' => $jobId]);
        });
    }

    /**
     * Clears references to a deleted WordPress user.
     *
     * Hooked to deleted_user so removing an account never leaves rows pointing
     * at a user id that no longer exists.
     */
    public static function onUserDeleted(int $userId): void
    {
        foreach (
            [
                'UPDATE {lists} SET created_by = NULL WHERE created_by = :id',
                'UPDATE {list_contacts} SET assigned_by = NULL WHERE assigned_by = :id',
                'UPDATE {campaigns} SET created_by = NULL WHERE created_by = :id',
                'UPDATE {import_jobs} SET created_by = NULL WHERE created_by = :id',
                'UPDATE {suppression_events} SET actor_user_id = NULL WHERE actor_user_id = :id',
                'UPDATE {alerts} SET acknowledged_by = NULL WHERE acknowledged_by = :id',
                'UPDATE {audit_logs} SET user_id = NULL WHERE user_id = :id',
            ] as $sql
        ) {
            Database::execute($sql, ['id' => $userId]);
        }
    }

    public static function register(): void
    {
        add_action('deleted_user', [self::class, 'onUserDeleted']);
    }

    /**
     * An IN() list of integers.
     *
     * The values are cast to int here, so there is nothing a caller could put
     * in them that survives as SQL.
     *
     * @param list<int> $ids
     */
    private static function inClause(array $ids): string
    {
        $clean = array_map('intval', $ids);
        return $clean === [] ? '0' : implode(',', $clean);
    }
}
