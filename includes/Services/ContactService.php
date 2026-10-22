<?php

declare(strict_types=1);

namespace TechzappCrm\Services;

use TechzappCrm\Core\Cleanup;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Support\EmailAddress;
use TechzappCrm\Support\Tokens;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contact reads and writes.
 *
 * The "Contacts" page shows unassigned contacts only; that filter is applied in
 * SQL (LEFT JOIN {list_contacts} … IS NULL), not in the frontend, so it cannot be
 * bypassed by crafting a request.
 */
final class ContactService
{
    public const SORTABLE = [
        'created_at'   => 'c.created_at',
        'updated_at'   => 'c.updated_at',
        'email'        => 'c.normalized_email',
        'first_name'   => 'c.first_name',
        'last_name'    => 'c.last_name',
        'company_name' => 'c.company_name',
        'country'      => 'c.country',
        'status'       => 'c.status',
    ];

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>, total:int, page:int, per_page:int, pages:int}
     */
    public static function paginate(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($filters['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        [$where, $params] = self::buildFilters($filters);

        $sortKey = (string) ($filters['sort'] ?? 'created_at');
        $column = self::SORTABLE[$sortKey] ?? self::SORTABLE['created_at'];
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $total = (int) Database::scalar(
            "SELECT COUNT(*)
               FROM {contacts} c
          LEFT JOIN {list_contacts} lc ON lc.contact_id = c.id
          LEFT JOIN {suppressions} s ON s.normalized_email = c.normalized_email
              WHERE {$where}",
            $params
        );

        $items = Database::all(
            "SELECT c.id, c.email, c.first_name, c.last_name, c.company_name, c.website,
                    c.country, c.source, c.status, c.custom_fields_json, c.unsubscribed_at,
                    c.created_at, c.updated_at,
                    lc.list_id, l.name AS list_name, lc.assigned_at,
                    s.reason AS suppression_reason, s.created_at AS suppressed_at
               FROM {contacts} c
          LEFT JOIN {list_contacts} lc ON lc.contact_id = c.id
          LEFT JOIN {lists} l ON l.id = lc.list_id
          LEFT JOIN {suppressions} s ON s.normalized_email = c.normalized_email
              WHERE {$where}
           ORDER BY {$column} {$direction}, c.id {$direction}
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items'    => array_map([self::class, 'present'], $items),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:array<string,mixed>}
     */
    public static function buildFilters(array $filters): array
    {
        $clauses = ['1 = 1'];
        $params = [];

        $assignment = (string) ($filters['assignment'] ?? 'any');
        if ($assignment === 'unassigned') {
            $clauses[] = 'lc.id IS NULL';
        } elseif ($assignment === 'assigned') {
            $clauses[] = 'lc.id IS NOT NULL';
        }

        if (!empty($filters['list_id'])) {
            $clauses[] = 'lc.list_id = :list_id';
            $params['list_id'] = (int) $filters['list_id'];
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // LIKE with a leading wildcard cannot use the index; it is bounded
            // by pagination and acceptable at this scale. Exact-email searches
            // take the indexed path below.
            if (str_contains($search, '@') && EmailAddress::isValid($search)) {
                $clauses[] = 'c.normalized_email = :exact_email';
                $params['exact_email'] = EmailAddress::normalize($search);
            } else {
                // One placeholder per column: PDO with emulation disabled
                // rejects the same named parameter appearing twice.
                $clauses[] = '(c.email LIKE :search_email OR c.first_name LIKE :search_first '
                    . 'OR c.last_name LIKE :search_last OR c.company_name LIKE :search_company '
                    . 'OR c.country LIKE :search_country)';
                $pattern = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                foreach (['search_email', 'search_first', 'search_last', 'search_company', 'search_country'] as $name) {
                    $params[$name] = $pattern;
                }
            }
        }

        if (!empty($filters['status'])) {
            $clauses[] = 'c.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['country'])) {
            $clauses[] = 'c.country = :country';
            $params['country'] = (string) $filters['country'];
        }

        if (!empty($filters['source'])) {
            $clauses[] = 'c.source = :source';
            $params['source'] = (string) $filters['source'];
        }

        $suppressed = $filters['suppressed'] ?? null;
        if ($suppressed === 'yes' || $suppressed === true) {
            $clauses[] = 's.id IS NOT NULL';
        } elseif ($suppressed === 'no' || $suppressed === false) {
            $clauses[] = 's.id IS NULL';
        }

        if (($filters['unsubscribed'] ?? null) === 'yes') {
            $clauses[] = 'c.unsubscribed_at IS NOT NULL';
        } elseif (($filters['unsubscribed'] ?? null) === 'no') {
            $clauses[] = 'c.unsubscribed_at IS NULL';
        }

        return [implode(' AND ', $clauses), $params];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::first(
            'SELECT c.*, lc.list_id, l.name AS list_name, lc.assigned_at,
                    s.reason AS suppression_reason, s.created_at AS suppressed_at
               FROM {contacts} c
          LEFT JOIN {list_contacts} lc ON lc.contact_id = c.id
          LEFT JOIN {lists} l ON l.id = lc.list_id
          LEFT JOIN {suppressions} s ON s.normalized_email = c.normalized_email
              WHERE c.id = :id',
            ['id' => $id]
        );

        return $row === null ? null : self::present($row);
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        $row = Database::first(
            'SELECT * FROM {contacts} WHERE normalized_email = :email',
            ['email' => EmailAddress::normalize($email)]
        );
        return $row === null ? null : self::present($row);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $normalized = EmailAddress::normalize((string) $data['email']);

        if (Database::scalar('SELECT id FROM {contacts} WHERE normalized_email = :e', ['e' => $normalized]) !== null) {
            throw CrmException::conflict('A contact with this email address already exists.', [
                'field' => 'email',
                'email' => $normalized,
            ]);
        }

        Database::execute(
            'INSERT INTO {contacts}
                (email, normalized_email, first_name, last_name, company_name, website,
                 country, source, custom_fields_json, status, unsubscribe_token,
                 created_at, updated_at)
             VALUES (:email, :normalized, :first, :last, :company, :website,
                     :country, :source, :custom, :status, :token,
                     :created_at, :updated_at)',
            [
                'email'      => (string) $data['email'],
                'normalized' => $normalized,
                'first'      => (string) ($data['first_name'] ?? ''),
                'last'       => (string) ($data['last_name'] ?? ''),
                'company'    => (string) ($data['company_name'] ?? ''),
                'website'    => (string) ($data['website'] ?? ''),
                'country'    => (string) ($data['country'] ?? ''),
                'source'     => (string) ($data['source'] ?? 'manual'),
                'custom'     => self::encodeCustom($data['custom_fields'] ?? null),
                'status'     => (string) ($data['status'] ?? 'active'),
                'token'      => Tokens::contact(),
                'created_at' => Database::nowUtc(),
                'updated_at' => Database::nowUtc(),
            ]
        );

        return Database::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $existing = Database::first('SELECT id, normalized_email FROM {contacts} WHERE id = :id', ['id' => $id]);
        if ($existing === null) {
            throw CrmException::notFound('Contact not found.');
        }

        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $normalized = EmailAddress::normalize((string) $data['email']);
            $clash = Database::scalar(
                'SELECT id FROM {contacts} WHERE normalized_email = :e AND id <> :id',
                ['e' => $normalized, 'id' => $id]
            );
            if ($clash !== null) {
                throw CrmException::conflict('Another contact already uses this email address.', ['field' => 'email']);
            }
            $fields[] = 'email = :email';
            $fields[] = 'normalized_email = :normalized';
            $params['email'] = (string) $data['email'];
            $params['normalized'] = $normalized;
        }

        foreach (['first_name', 'last_name', 'company_name', 'website', 'country', 'source', 'status'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = (string) $data[$field];
            }
        }

        if (array_key_exists('custom_fields', $data)) {
            $fields[] = 'custom_fields_json = :custom';
            $params['custom'] = self::encodeCustom($data['custom_fields']);
        }

        if ($fields === []) {
            return;
        }

        $fields[] = 'updated_at = :updated_at';
        $params['updated_at'] = Database::nowUtc();

        Database::execute('UPDATE {contacts} SET ' . implode(', ', $fields) . ' WHERE id = :id', $params);
    }

    public static function delete(int $id): bool
    {
        // Suppression rows survive: they key on the email address, not the id.
        // Cleanup releases list membership and detaches campaign history.
        return Cleanup::deleteContacts([$id]) > 0;
    }

    /** @param list<int> $ids @return int number deleted */
    public static function deleteMany(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        return Cleanup::deleteContacts($ids);
    }

    /** @return array{total:int, unassigned:int, assigned:int, suppressed:int, unsubscribed:int} */
    public static function counts(): array
    {
        $row = Database::first(
            'SELECT
               (SELECT COUNT(*) FROM {contacts}) AS total,
               (SELECT COUNT(*) FROM {contacts} c LEFT JOIN {list_contacts} lc ON lc.contact_id = c.id WHERE lc.id IS NULL) AS unassigned,
               (SELECT COUNT(*) FROM {list_contacts}) AS assigned,
               (SELECT COUNT(*) FROM {contacts} c JOIN {suppressions} s ON s.normalized_email = c.normalized_email) AS suppressed,
               (SELECT COUNT(*) FROM {contacts} WHERE unsubscribed_at IS NOT NULL) AS unsubscribed'
        ) ?? [];

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'unassigned'   => (int) ($row['unassigned'] ?? 0),
            'assigned'     => (int) ($row['assigned'] ?? 0),
            'suppressed'   => (int) ($row['suppressed'] ?? 0),
            'unsubscribed' => (int) ($row['unsubscribed'] ?? 0),
        ];
    }

    /**
     * Yields contacts for export, a page at a time.
     *
     * Keyset pagination (id > last) rather than OFFSET: the export can run to
     * tens of thousands of rows and OFFSET gets slower the further it goes.
     * Only one page is ever held in memory.
     *
     * @param array<string,mixed> $filters
     * @return \Generator<int, array<string,mixed>>
     */
    public static function streamForExport(array $filters): \Generator
    {
        [$where, $params] = self::buildFilters($filters);

        $lastId = 0;

        do {
            $rows = Database::all(
                "SELECT c.id, c.email, c.first_name, c.last_name, c.company_name, c.website,
                        c.country, c.source, c.status, l.name AS list_name, c.unsubscribed_at,
                        s.reason AS suppression_reason, c.created_at, c.updated_at
                   FROM {contacts} c
              LEFT JOIN {list_contacts} lc ON lc.contact_id = c.id
              LEFT JOIN {lists} l ON l.id = lc.list_id
              LEFT JOIN {suppressions} s ON s.normalized_email = c.normalized_email
                  WHERE {$where} AND c.id > :last_id
               ORDER BY c.id
                  LIMIT 1000",
                $params + ['last_id' => $lastId]
            );

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                yield $row;
            }
        } while (count($rows) === 1000);
    }

    /** @return list<string> */
    public static function distinctValues(string $column): array
    {
        if (!in_array($column, ['country', 'source'], true)) {
            return [];
        }
        $rows = Database::all(
            "SELECT DISTINCT {$column} AS value FROM {contacts} WHERE {$column} <> '' ORDER BY {$column} LIMIT 500"
        );
        return array_map(static fn (array $row): string => (string) $row['value'], $rows);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function present(array $row): array
    {
        $custom = $row['custom_fields_json'] ?? null;
        if (is_string($custom) && $custom !== '') {
            $decoded = json_decode($custom, true);
            $custom = is_array($decoded) ? $decoded : [];
        }

        $row['custom_fields'] = is_array($custom) ? $custom : [];
        unset($row['custom_fields_json'], $row['unsubscribe_token']);

        $row['id'] = (int) $row['id'];
        $row['is_suppressed'] = !empty($row['suppression_reason']);
        $row['is_unsubscribed'] = !empty($row['unsubscribed_at']);
        if (array_key_exists('list_id', $row)) {
            $row['list_id'] = $row['list_id'] === null ? null : (int) $row['list_id'];
        }

        return $row;
    }

    private static function encodeCustom(mixed $custom): ?string
    {
        if (!is_array($custom) || $custom === []) {
            return null;
        }
        $json = wp_json_encode($custom, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }
}
