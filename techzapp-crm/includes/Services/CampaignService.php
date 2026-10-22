<?php

declare(strict_types=1);

namespace TechzappCrm\Services;

use TechzappCrm\Core\Audit;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\Settings;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Services\Mail\Mailer;
use TechzappCrm\Support\EmailAddress;
use TechzappCrm\Support\EmailHtml;
use TechzappCrm\Support\Personalization;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign lifecycle.
 *
 * State transitions are validated here AND enforced in SQL: every change is an
 * UPDATE with a WHERE clause naming the permitted source states, so a cron run
 * that overlaps with a user clicking "cancel" cannot resurrect a campaign.
 */
final class CampaignService
{
    public const STATUSES = ['draft', 'scheduled', 'queued', 'sending', 'paused', 'completed', 'failed', 'cancelled'];

    /** Legal transitions: from => [to, …]. */
    public const TRANSITIONS = [
        'draft'     => ['scheduled', 'queued', 'cancelled'],
        'scheduled' => ['draft', 'queued', 'cancelled'],
        'queued'    => ['sending', 'paused', 'cancelled', 'failed'],
        'sending'   => ['paused', 'completed', 'failed', 'cancelled'],
        'paused'    => ['queued', 'sending', 'cancelled'],
        'completed' => [],
        'failed'    => ['draft', 'scheduled', 'queued', 'cancelled'],
        'cancelled' => [],
    ];

    /** Statuses where the campaign still owns queued recipients. */
    public const ACTIVE_STATUSES = ['queued', 'sending', 'paused'];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>, total:int, page:int, per_page:int, pages:int}
     */
    public static function paginate(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;

        $clauses = ['1 = 1'];
        $params = [];

        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $statuses = array_values(array_intersect($statuses, self::STATUSES));

            if ($statuses === []) {
                // The caller asked for a status that does not exist. Returning
                // every campaign would quietly answer a different question.
                $clauses[] = '1 = 0';
            } else {
                $names = [];
                foreach ($statuses as $index => $status) {
                    $names[] = ':status' . $index;
                    $params['status' . $index] = $status;
                }
                $clauses[] = 'c.status IN (' . implode(',', $names) . ')';
            }
        }
        if (!empty($filters['list_id'])) {
            $clauses[] = 'c.list_id = :list_id';
            $params['list_id'] = (int) $filters['list_id'];
        }
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $clauses[] = '(c.name LIKE :search_name OR c.subject LIKE :search_subject)';
            $pattern = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['search'])) . '%';
            $params['search_name'] = $pattern;
            $params['search_subject'] = $pattern;
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'c.created_at >= :from';
            $params['from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'c.created_at <= :to';
            $params['to'] = (string) $filters['to'];
        }

        $where = implode(' AND ', $clauses);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM {campaigns} c WHERE {$where}", $params);

        $items = Database::all(
            "SELECT c.id, c.name, c.status, c.subject, c.from_name, c.from_email, c.list_id,
                    c.scheduled_at_utc, c.timezone, c.total_recipients, c.started_at, c.completed_at,
                    c.created_at, c.updated_at, c.last_error, l.name AS list_name,
                    st.accepted, st.delivered, st.hard_bounces, st.soft_bounces, st.complaints,
                    st.unique_opens, st.unique_clicks, st.pending, st.failed
               FROM {campaigns} c
          LEFT JOIN {lists} l ON l.id = c.list_id
          LEFT JOIN {campaign_stats} st ON st.campaign_id = c.id
              WHERE {$where}
           ORDER BY c.created_at DESC, c.id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'items'    => array_map([self::class, 'present'], $items),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::first(
            'SELECT c.*, l.name AS list_name
               FROM {campaigns} c
          LEFT JOIN {lists} l ON l.id = c.list_id
              WHERE c.id = :id',
            ['id' => $id]
        );
        return $row === null ? null : self::present($row);
    }

    /** @return array<string,mixed> */
    public static function findOrFail(int $id): array
    {
        $campaign = self::find($id);
        if ($campaign === null) {
            throw CrmException::notFound('Campaign not found.');
        }
        return $campaign;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data, ?int $userId): int
    {
        Database::execute(
            'INSERT INTO {campaigns}
                (name, list_id, from_name, from_email, reply_to, subject, preview_text,
                 html_body, text_body, status, timezone, send_rate_per_minute,
                 track_opens, track_clicks, created_by, created_at, updated_at)
             VALUES (:name, :list_id, :from_name, :from_email, :reply_to, :subject, :preview,
                     :html, :text, :status, :timezone, :rate, :opens, :clicks, :user,
                     :created_at, :updated_at)',
            [
                'name'       => (string) $data['name'],
                'list_id'    => $data['list_id'] ?? null,
                'from_name'  => (string) ($data['from_name'] ?? ''),
                'from_email' => (string) ($data['from_email'] ?? ''),
                'reply_to'   => (string) ($data['reply_to'] ?? ''),
                'subject'    => (string) ($data['subject'] ?? ''),
                'preview'    => (string) ($data['preview_text'] ?? ''),
                'html'       => self::sanitizeBody($data['html_body'] ?? null),
                'text'       => $data['text_body'] ?? null,
                'status'     => 'draft',
                'timezone'   => (string) ($data['timezone'] ?? Settings::string('default_timezone', 'UTC')),
                'rate'       => self::clampRate($data['send_rate_per_minute'] ?? null),
                'opens'      => !empty($data['track_opens']) ? 1 : 0,
                'clicks'     => !empty($data['track_clicks']) ? 1 : 0,
                'user'       => $userId,
                'created_at' => Database::nowUtc(),
                'updated_at' => Database::nowUtc(),
            ]
        );

        return Database::lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $campaign = self::findOrFail($id);

        // Content is editable only while the campaign is not live. A paused
        // campaign already has rendered messages in flight; editing it would
        // make the report describe two different emails.
        if (!in_array($campaign['status'], ['draft', 'scheduled', 'failed'], true)) {
            throw CrmException::conflict(
                'Only draft, scheduled or failed campaigns can be edited. Duplicate this campaign to make changes.',
                ['status' => $campaign['status']]
            );
        }

        $fields = [];
        $params = ['id' => $id];

        $map = [
            'name'         => 'name',
            'from_name'    => 'from_name',
            'from_email'   => 'from_email',
            'reply_to'     => 'reply_to',
            'subject'      => 'subject',
            'preview_text' => 'preview_text',
            'text_body'    => 'text_body',
            'timezone'     => 'timezone',
        ];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $fields[] = "{$column} = :{$column}";
                $params[$column] = (string) $data[$key];
            }
        }

        if (array_key_exists('list_id', $data)) {
            $fields[] = 'list_id = :list_id';
            $params['list_id'] = $data['list_id'] === null ? null : (int) $data['list_id'];
        }
        if (array_key_exists('html_body', $data)) {
            $fields[] = 'html_body = :html_body';
            $params['html_body'] = self::sanitizeBody($data['html_body']);
        }
        if (array_key_exists('send_rate_per_minute', $data) && $data['send_rate_per_minute'] !== null) {
            $fields[] = 'send_rate_per_minute = :rate';
            $params['rate'] = self::clampRate($data['send_rate_per_minute']);
        }
        foreach (['track_opens' => 'track_opens', 'track_clicks' => 'track_clicks'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$column} = :{$column}";
                $params[$column] = !empty($data[$key]) ? 1 : 0;
            }
        }

        // Editing a scheduled campaign returns it to draft: the operator must
        // re-confirm the schedule after changing the content.
        if ($campaign['status'] === 'scheduled' && $fields !== []) {
            $fields[] = "status = 'draft'";
            $fields[] = 'scheduled_at_utc = NULL';
        }

        if ($fields === []) {
            return;
        }

        $fields[] = 'updated_at = :updated_at';
        $params['updated_at'] = Database::nowUtc();

        Database::execute('UPDATE {campaigns} SET ' . implode(', ', $fields) . ' WHERE id = :id', $params);
    }

    public static function duplicate(int $id, ?int $userId): int
    {
        $campaign = self::findOrFail($id);

        Database::execute(
            'INSERT INTO {campaigns}
                (name, list_id, from_name, from_email, reply_to, subject, preview_text,
                 html_body, text_body, status, timezone, send_rate_per_minute,
                 track_opens, track_clicks, created_by, created_at, updated_at)
             SELECT CONCAT(LEFT(name, 170), :suffix), list_id, from_name, from_email, reply_to,
                    subject, preview_text, html_body, text_body, :status, timezone,
                    send_rate_per_minute, track_opens, track_clicks, :user,
                    :created_at, :updated_at
               FROM {campaigns} WHERE id = :id',
            [
                'suffix'     => ' (copy)',
                'status'     => 'draft',
                'user'       => $userId,
                'created_at' => Database::nowUtc(),
                'updated_at' => Database::nowUtc(),
                'id'         => $id,
            ]
        );

        $newId = Database::lastInsertId();
        Audit::log('campaign.duplicated', 'campaign', $newId, ['source_campaign_id' => $campaign['id']], $userId);
        return $newId;
    }

    public static function delete(int $id): void
    {
        $campaign = self::findOrFail($id);

        if (!in_array($campaign['status'], ['draft', 'cancelled', 'failed'], true)) {
            throw CrmException::conflict(
                'Only draft, cancelled or failed campaigns can be deleted. Cancel the campaign first.',
                ['status' => $campaign['status']]
            );
        }

        \TechzappCrm\Core\Cleanup::deleteCampaign($id);
    }

    /**
     * Moves a campaign to a new status, enforcing the transition in SQL.
     *
     * @param list<string> $extraSets additional SET fragments
     * @param array<string,mixed> $params
     */
    public static function transition(int $id, string $to, array $extraSets = [], array $params = []): bool
    {
        $from = array_keys(array_filter(
            self::TRANSITIONS,
            static fn (array $targets): bool => in_array($to, $targets, true)
        ));
        if ($from === []) {
            return false;
        }

        $names = [];
        foreach ($from as $index => $status) {
            $names[] = ':from' . $index;
            $params['from' . $index] = $status;
        }

        $sets = array_merge(['status = :to', 'updated_at = :updated_at'], $extraSets);
        $params['to'] = $to;
        $params['updated_at'] = Database::nowUtc();
        $params['id'] = $id;

        return Database::execute(
            'UPDATE {campaigns} SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND status IN (' . implode(',', $names) . ')',
            $params
        ) > 0;
    }

    /** Schedules a campaign for a UTC instant. */
    public static function schedule(int $id, string $scheduledAtUtc, string $timezone, ?int $sendRate, ?int $userId): array
    {
        $campaign = self::findOrFail($id);
        $readiness = self::readiness($campaign);

        if (!$readiness['can_send']) {
            throw CrmException::unprocessable('This campaign is not ready to send.', $readiness);
        }
        if (!self::canTransition((string) $campaign['status'], 'scheduled')) {
            throw CrmException::conflict(
                "A campaign with status '{$campaign['status']}' cannot be scheduled.",
                ['status' => $campaign['status']]
            );
        }
        if (strtotime($scheduledAtUtc . ' UTC') < time() - 60) {
            throw CrmException::validation(['scheduled_at' => 'Choose a time in the future.']);
        }

        $sets = ['scheduled_at_utc = :scheduled', 'timezone = :timezone', 'last_error = \'\''];
        $params = ['scheduled' => $scheduledAtUtc, 'timezone' => $timezone];
        if ($sendRate !== null) {
            $sets[] = 'send_rate_per_minute = :rate';
            $params['rate'] = self::clampRate($sendRate);
        }

        if (!self::transition($id, 'scheduled', $sets, $params)) {
            throw CrmException::conflict('The campaign status changed. Reload and try again.');
        }

        Audit::log('campaign.scheduled', 'campaign', $id, [
            'scheduled_at_utc' => $scheduledAtUtc,
            'timezone'         => $timezone,
            'eligible'         => $readiness['counts']['eligible'],
        ], $userId);

        return self::findOrFail($id);
    }

    /** Queues a campaign for immediate processing by the next cron run. */
    public static function sendNow(int $id, ?int $userId): array
    {
        $campaign = self::findOrFail($id);
        $readiness = self::readiness($campaign);

        if (!$readiness['can_send']) {
            throw CrmException::unprocessable('This campaign is not ready to send.', $readiness);
        }
        if (!self::canTransition((string) $campaign['status'], 'queued')) {
            throw CrmException::conflict("A campaign with status '{$campaign['status']}' cannot be started.");
        }

        if (!self::transition(
            $id,
            'queued',
            ['queued_at = UTC_TIMESTAMP()', 'scheduled_at_utc = COALESCE(scheduled_at_utc, UTC_TIMESTAMP())', "last_error = ''"]
        )) {
            throw CrmException::conflict('The campaign status changed. Reload and try again.');
        }

        Audit::log('campaign.queued', 'campaign', $id, ['eligible' => $readiness['counts']['eligible']], $userId);
        return self::findOrFail($id);
    }

    public static function cancel(int $id, ?int $userId): array
    {
        $campaign = self::findOrFail($id);
        if (in_array($campaign['status'], ['completed', 'cancelled'], true)) {
            throw CrmException::conflict("A {$campaign['status']} campaign cannot be cancelled.");
        }

        Database::transaction(static function () use ($id): void {
            self::transition($id, 'cancelled', ['cancelled_at = UTC_TIMESTAMP()']);
            // Recipients that never left the queue are marked cancelled so the
            // report distinguishes them from failures.
            Database::execute(
                "UPDATE {campaign_recipients} SET status = 'cancelled'
                  WHERE campaign_id = :id AND status IN ('pending','needs_reconciliation')",
                ['id' => $id]
            );
        });

        Audit::log('campaign.cancelled', 'campaign', $id, [], $userId);
        return self::findOrFail($id);
    }

    public static function pause(int $id, ?int $userId): array
    {
        $campaign = self::findOrFail($id);
        if (!self::canTransition((string) $campaign['status'], 'paused')) {
            throw CrmException::conflict("A campaign with status '{$campaign['status']}' cannot be paused.");
        }
        if (!self::transition($id, 'paused')) {
            throw CrmException::conflict('The campaign status changed. Reload and try again.');
        }

        Audit::log('campaign.paused', 'campaign', $id, [], $userId);
        return self::findOrFail($id);
    }

    public static function resume(int $id, ?int $userId): array
    {
        $campaign = self::findOrFail($id);
        if ($campaign['status'] !== 'paused') {
            throw CrmException::conflict('Only a paused campaign can be resumed.');
        }
        if (!Settings::sendingAllowed()) {
            throw CrmException::conflict('Sending is disabled by the global kill switch. Release it in Settings first.');
        }
        if (!self::transition($id, 'queued', ["last_error = ''"])) {
            throw CrmException::conflict('The campaign status changed. Reload and try again.');
        }

        Audit::log('campaign.resumed', 'campaign', $id, [], $userId);
        return self::findOrFail($id);
    }

    /**
     * Recipient counts for the review step.
     *
     * @return array{list_total:int, suppressed:int, unsubscribed:int, invalid:int, eligible:int}
     */
    public static function recipientCounts(?int $listId): array
    {
        if ($listId === null) {
            return ['list_total' => 0, 'suppressed' => 0, 'unsubscribed' => 0, 'invalid' => 0, 'eligible' => 0];
        }

        $row = Database::first(
            "SELECT
                COUNT(*) AS list_total,
                SUM(CASE WHEN s.id IS NOT NULL THEN 1 ELSE 0 END) AS suppressed,
                SUM(CASE WHEN s.id IS NULL AND c.unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribed,
                SUM(CASE WHEN s.id IS NULL AND c.unsubscribed_at IS NULL
                          AND (c.normalized_email NOT LIKE '%@%.%' OR c.status <> 'active') THEN 1 ELSE 0 END) AS invalid,
                SUM(CASE WHEN s.id IS NULL AND c.unsubscribed_at IS NULL
                          AND c.normalized_email LIKE '%@%.%' AND c.status = 'active' THEN 1 ELSE 0 END) AS eligible
               FROM {list_contacts} lc
               JOIN {contacts} c ON c.id = lc.contact_id
          LEFT JOIN {suppressions} s ON s.normalized_email = c.normalized_email
              WHERE lc.list_id = :list_id",
            ['list_id' => $listId]
        ) ?? [];

        return [
            'list_total'   => (int) ($row['list_total'] ?? 0),
            'suppressed'   => (int) ($row['suppressed'] ?? 0),
            'unsubscribed' => (int) ($row['unsubscribed'] ?? 0),
            'invalid'      => (int) ($row['invalid'] ?? 0),
            'eligible'     => (int) ($row['eligible'] ?? 0),
        ];
    }

    /**
     * Pre-flight check shown on the review step and re-run before scheduling.
     *
     * @param array<string,mixed> $campaign
     * @return array{can_send:bool, problems:list<array{field:string,message:string}>, warnings:list<string>, counts:array<string,int>, estimate:array<string,mixed>, sender_verified:bool, missing_variables:list<string>}
     */
    public static function readiness(array $campaign): array
    {
        $problems = [];
        $warnings = [];

        if (trim((string) $campaign['name']) === '') {
            $problems[] = ['field' => 'name', 'message' => 'Give the campaign an internal name.'];
        }
        if (empty($campaign['list_id'])) {
            $problems[] = ['field' => 'list_id', 'message' => 'Choose a recipient list.'];
        }
        if (trim((string) $campaign['subject']) === '') {
            $problems[] = ['field' => 'subject', 'message' => 'A subject line is required.'];
        }
        if (trim((string) ($campaign['html_body'] ?? '')) === '' && trim((string) ($campaign['text_body'] ?? '')) === '') {
            $problems[] = ['field' => 'html_body', 'message' => 'The email has no content.'];
        }

        $fromEmail = (string) $campaign['from_email'];
        if ($fromEmail === '' || !EmailAddress::isValid($fromEmail)) {
            $problems[] = ['field' => 'from_email', 'message' => 'A valid From address is required.'];
            $senderVerified = false;
        } else {
            $senderVerified = Mailer::transport()->isIdentityUsable($fromEmail);
            if (!$senderVerified) {
                $problems[] = [
                    'field'   => 'from_email',
                    'message' => 'This sender is not a verified SES identity for this account.',
                ];
            }
        }

        $replyTo = (string) ($campaign['reply_to'] ?? '');
        if ($replyTo !== '' && !EmailAddress::isValid($replyTo)) {
            $problems[] = ['field' => 'reply_to', 'message' => 'Reply-to must be a valid email address.'];
        }

        $counts = self::recipientCounts($campaign['list_id'] === null ? null : (int) $campaign['list_id']);
        if ($counts['eligible'] === 0 && !empty($campaign['list_id'])) {
            $problems[] = ['field' => 'list_id', 'message' => 'This list has no eligible recipients.'];
        }
        if ($counts['suppressed'] > 0) {
            $warnings[] = "{$counts['suppressed']} contact(s) on this list are suppressed and will be skipped.";
        }
        if ($counts['unsubscribed'] > 0) {
            $warnings[] = "{$counts['unsubscribed']} contact(s) have unsubscribed and will be skipped.";
        }

        if (!Settings::sendingAllowed()) {
            $problems[] = [
                'field'   => 'sending',
                'message' => 'Sending is currently disabled by the global kill switch.',
            ];
        }

        // Which personalization variables have no value for at least one recipient?
        $missing = self::missingVariables($campaign);
        if ($missing !== []) {
            $warnings[] = 'Some recipients are missing values for: ' . implode(', ', $missing)
                . '. The configured fallback will be used.';
        }

        $rate = self::clampRate($campaign['send_rate_per_minute'] ?? null);
        $minutes = $rate > 0 ? (int) ceil($counts['eligible'] / $rate) : 0;

        return [
            'can_send'          => $problems === [],
            'problems'          => $problems,
            'warnings'          => $warnings,
            'counts'            => $counts,
            'sender_verified'   => $senderVerified,
            'missing_variables' => $missing,
            'estimate'          => [
                'send_rate_per_minute' => $rate,
                'estimated_minutes'    => $minutes,
                'transport'            => Mailer::transport()->name(),
            ],
        ];
    }

    /** @param array<string,mixed> $campaign @return list<string> */
    public static function missingVariables(array $campaign): array
    {
        $listId = $campaign['list_id'] ?? null;
        if ($listId === null) {
            return [];
        }

        $template = implode(' ', [
            (string) $campaign['subject'],
            (string) ($campaign['preview_text'] ?? ''),
            (string) ($campaign['html_body'] ?? ''),
            (string) ($campaign['text_body'] ?? ''),
        ]);

        $variables = Personalization::variablesIn($template);
        if ($variables === []) {
            return [];
        }

        $columns = [
            'first_name'   => 'c.first_name',
            'last_name'    => 'c.last_name',
            'full_name'    => "CONCAT(c.first_name, c.last_name)",
            'company_name' => 'c.company_name',
            'website'      => 'c.website',
            'country'      => 'c.country',
            'email'        => 'c.email',
        ];

        $selects = [];
        foreach ($variables as $variable) {
            if (isset($columns[$variable])) {
                $selects[$variable] = "SUM(CASE WHEN TRIM(COALESCE({$columns[$variable]}, '')) = '' THEN 1 ELSE 0 END) AS `missing_{$variable}`";
            }
        }

        $missing = [];
        if ($selects !== []) {
            $row = Database::first(
                'SELECT ' . implode(', ', $selects) . '
                   FROM {list_contacts} lc
                   JOIN {contacts} c ON c.id = lc.contact_id
                  WHERE lc.list_id = :list_id',
                ['list_id' => (int) $listId]
            ) ?? [];

            foreach ($selects as $variable => $_) {
                if ((int) ($row['missing_' . $variable] ?? 0) > 0) {
                    $missing[] = $variable;
                }
            }
        }

        // Variables that are neither standard fields nor known custom fields
        // cannot be resolved at all; surface them too.
        foreach ($variables as $variable) {
            if (!isset($columns[$variable]) && !in_array($variable, $missing, true)) {
                $missing[] = $variable;
            }
        }

        return $missing;
    }

    public static function clampRate(mixed $rate): int
    {
        $maximum = max(1, Settings::int('max_send_rate_per_minute', 120));
        $value = is_numeric($rate) ? (int) $rate : $maximum;
        return max(1, min($maximum, $value));
    }

    private static function sanitizeBody(mixed $html): ?string
    {
        if (!is_string($html) || trim($html) === '') {
            return null;
        }
        return EmailHtml::sanitize($html);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function present(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['list_id'] = isset($row['list_id']) && $row['list_id'] !== null ? (int) $row['list_id'] : null;
        $row['total_recipients'] = (int) ($row['total_recipients'] ?? 0);

        foreach (['track_opens', 'track_clicks'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = (bool) $row[$flag];
            }
        }
        if (array_key_exists('send_rate_per_minute', $row)) {
            $row['send_rate_per_minute'] = (int) $row['send_rate_per_minute'];
        }
        foreach (['accepted', 'delivered', 'hard_bounces', 'soft_bounces', 'complaints', 'unique_opens', 'unique_clicks', 'pending', 'failed'] as $metric) {
            if (array_key_exists($metric, $row)) {
                $row[$metric] = (int) ($row[$metric] ?? 0);
            }
        }

        return $row;
    }
}
