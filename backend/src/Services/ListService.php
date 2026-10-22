<?php

declare(strict_types=1);

namespace Techzapp\Crm\Services;

use PDO;
use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Http\ApiException;

/**
 * Lists and exclusive contact assignment.
 *
 * The exclusivity rule is enforced by UNIQUE(contact_id) on list_contacts. An
 * assignment is an upsert on that key, so two concurrent requests assigning the
 * same contact to different lists can only ever leave one row behind — the
 * database decides, not application-level checking.
 */
final class ListService
{
    /** @return list<array<string,mixed>> */
    public static function all(string $search = '', int $limit = 200): array
    {
        $params = [];
        $where = '1 = 1';
        if (trim($search) !== '') {
            $where = '(l.name LIKE :search_name OR l.description LIKE :search_description)';
            $pattern = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($search)) . '%';
            $params['search_name'] = $pattern;
            $params['search_description'] = $pattern;
        }

        $rows = Database::all(
            "SELECT l.id, l.name, l.description, l.created_at, l.updated_at,
                    COUNT(lc.id) AS contact_count,
                    SUM(CASE WHEN s.id IS NULL AND c.unsubscribed_at IS NULL THEN 1 ELSE 0 END) AS sendable_count
               FROM lists l
          LEFT JOIN list_contacts lc ON lc.list_id = l.id
          LEFT JOIN contacts c ON c.id = lc.contact_id
          LEFT JOIN suppressions s ON s.normalized_email = c.normalized_email
              WHERE {$where}
           GROUP BY l.id, l.name, l.description, l.created_at, l.updated_at
           ORDER BY l.name
              LIMIT " . max(1, min(500, $limit)),
            $params
        );

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['contact_count'] = (int) $row['contact_count'];
            $row['sendable_count'] = (int) $row['sendable_count'];
            return $row;
        }, $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::first(
            'SELECT l.id, l.name, l.description, l.created_at, l.updated_at,
                    (SELECT COUNT(*) FROM list_contacts WHERE list_id = l.id) AS contact_count,
                    (SELECT COUNT(*)
                       FROM list_contacts lc
                       JOIN contacts c ON c.id = lc.contact_id
                  LEFT JOIN suppressions s ON s.normalized_email = c.normalized_email
                      WHERE lc.list_id = l.id AND s.id IS NULL AND c.unsubscribed_at IS NULL) AS sendable_count
               FROM lists l WHERE l.id = :id',
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['contact_count'] = (int) $row['contact_count'];
        $row['sendable_count'] = (int) $row['sendable_count'];
        return $row;
    }

    public static function create(string $name, string $description, ?int $userId): int
    {
        if (self::nameExists($name, null)) {
            throw ApiException::conflict('A list with this name already exists.', ['field' => 'name']);
        }

        Database::execute(
            'INSERT INTO lists (name, description, created_by) VALUES (:name, :description, :user)',
            ['name' => $name, 'description' => $description, 'user' => $userId]
        );

        return Database::lastInsertId();
    }

    public static function rename(int $id, string $name, ?string $description): void
    {
        if (self::find($id) === null) {
            throw ApiException::notFound('List not found.');
        }
        if (self::nameExists($name, $id)) {
            throw ApiException::conflict('A list with this name already exists.', ['field' => 'name']);
        }

        $fields = ['name = :name'];
        $params = ['name' => $name, 'id' => $id];
        if ($description !== null) {
            $fields[] = 'description = :description';
            $params['description'] = $description;
        }

        Database::execute('UPDATE lists SET ' . implode(', ', $fields) . ' WHERE id = :id', $params);
    }

    /**
     * Deletes a list. Contacts are never deleted — they return to the
     * unassigned pool. A list referenced by a campaign is refused.
     *
     * @return array{released:int}
     */
    public static function delete(int $id, bool $confirmed): array
    {
        $list = self::find($id);
        if ($list === null) {
            throw ApiException::notFound('List not found.');
        }

        if (!$confirmed) {
            throw ApiException::conflict(
                'Deleting this list will return its contacts to the unassigned pool. Confirm to proceed.',
                ['requires_confirmation' => true, 'contact_count' => $list['contact_count']]
            );
        }

        $campaigns = (int) Database::scalar(
            'SELECT COUNT(*) FROM campaigns WHERE list_id = :id',
            ['id' => $id]
        );
        if ($campaigns > 0) {
            throw ApiException::conflict(
                'This list is used by ' . $campaigns . ' campaign(s). Delete or reassign those campaigns first.',
                ['campaign_count' => $campaigns]
            );
        }

        return Database::transaction(static function () use ($id, $list): array {
            // Explicit delete (rather than relying on ON DELETE CASCADE) keeps
            // the released count accurate and the intent obvious.
            $released = Database::execute('DELETE FROM list_contacts WHERE list_id = :id', ['id' => $id]);
            Database::execute('DELETE FROM lists WHERE id = :id', ['id' => $id]);

            Audit::log('list.deleted', 'list', $id, [
                'name'              => $list['name'],
                'contacts_released' => $released,
            ]);

            return ['released' => $released];
        });
    }

    /**
     * Assigns contacts to a list, replacing any previous membership.
     *
     * @param list<int> $contactIds
     * @return array{assigned:int, moved:int, missing:int, unchanged:int}
     */
    public static function assign(int $listId, array $contactIds, ?int $userId): array
    {
        if (self::find($listId) === null) {
            throw ApiException::notFound('List not found.');
        }
        if ($contactIds === []) {
            return ['assigned' => 0, 'moved' => 0, 'missing' => 0, 'unchanged' => 0];
        }

        return Database::transaction(static function (PDO $pdo) use ($listId, $contactIds, $userId): array {
            $assigned = 0;
            $moved = 0;
            $unchanged = 0;
            $existingIds = [];

            foreach (array_chunk($contactIds, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));

                // Lock the rows we are about to (re)assign so a concurrent
                // transaction cannot interleave a second membership.
                $rows = Database::all(
                    "SELECT c.id, lc.list_id
                       FROM contacts c
                  LEFT JOIN list_contacts lc ON lc.contact_id = c.id
                      WHERE c.id IN ({$placeholders})
                        FOR UPDATE",
                    $chunk
                );

                $values = [];
                $params = [];
                foreach ($rows as $row) {
                    $contactId = (int) $row['id'];
                    $existingIds[] = $contactId;
                    $currentList = $row['list_id'] === null ? null : (int) $row['list_id'];

                    if ($currentList === $listId) {
                        $unchanged++;
                        continue;
                    }
                    if ($currentList !== null) {
                        $moved++;
                    } else {
                        $assigned++;
                    }

                    $values[] = '(?, ?, UTC_TIMESTAMP(), ?)';
                    array_push($params, $listId, $contactId, $userId);
                }

                if ($values !== []) {
                    // UNIQUE(contact_id) turns this into "move" semantics.
                    $sql = 'INSERT INTO list_contacts (list_id, contact_id, assigned_at, assigned_by) VALUES '
                        . implode(',', $values)
                        . ' ON DUPLICATE KEY UPDATE list_id = VALUES(list_id),
                             assigned_at = VALUES(assigned_at), assigned_by = VALUES(assigned_by)';
                    Database::execute($sql, $params);
                }
            }

            return [
                'assigned'  => $assigned,
                'moved'     => $moved,
                'unchanged' => $unchanged,
                'missing'   => count(array_unique($contactIds)) - count(array_unique($existingIds)),
            ];
        });
    }

    /**
     * Removes contacts from a list, returning them to the unassigned pool.
     *
     * @param list<int> $contactIds empty means "remove every member"
     */
    public static function removeContacts(int $listId, array $contactIds): int
    {
        if (self::find($listId) === null) {
            throw ApiException::notFound('List not found.');
        }

        if ($contactIds === []) {
            return Database::execute('DELETE FROM list_contacts WHERE list_id = :id', ['id' => $listId]);
        }

        $removed = 0;
        foreach (array_chunk($contactIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $removed += Database::execute(
                "DELETE FROM list_contacts WHERE list_id = ? AND contact_id IN ({$placeholders})",
                array_merge([$listId], $chunk)
            );
        }
        return $removed;
    }

    /**
     * Assigns every contact matching a filter (e.g. "all unassigned contacts
     * matching this search") without the client enumerating ids.
     *
     * @param array<string,mixed> $filters
     * @return array{assigned:int, moved:int, unchanged:int, missing:int}
     */
    public static function assignByFilter(int $listId, array $filters, ?int $userId, int $maxContacts = 100000): array
    {
        [$where, $params] = ContactService::buildFilters($filters);

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            Database::all(
                "SELECT c.id
                   FROM contacts c
              LEFT JOIN list_contacts lc ON lc.contact_id = c.id
              LEFT JOIN suppressions s ON s.normalized_email = c.normalized_email
                  WHERE {$where}
                  LIMIT {$maxContacts}",
                $params
            )
        );

        return self::assign($listId, $ids, $userId);
    }

    /** @return list<array<string,mixed>> Campaigns that targeted this list. */
    public static function campaignHistory(int $listId, int $limit = 50): array
    {
        return Database::all(
            'SELECT id, name, status, scheduled_at_utc, started_at, completed_at, total_recipients
               FROM campaigns
              WHERE list_id = :id
           ORDER BY COALESCE(started_at, scheduled_at_utc, created_at) DESC
              LIMIT ' . max(1, min(200, $limit)),
            ['id' => $listId]
        );
    }

    private static function nameExists(string $name, ?int $exceptId): bool
    {
        $sql = 'SELECT id FROM lists WHERE name = :name';
        $params = ['name' => $name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return Database::scalar($sql, $params) !== null;
    }
}
