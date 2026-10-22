<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\View;
use TechzappCrm\Core\Capabilities;
use TechzappCrm\Core\CrmException;
use TechzappCrm\Core\Database;
use TechzappCrm\Services\SuppressionService;
use TechzappCrm\Support\EmailAddress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Addresses that must never be sent to again.
 *
 * Suppression is the mechanism that keeps a sending reputation intact: a hard
 * bounce or a complaint lands here permanently, and every campaign filters
 * against this table before building its recipient set.
 */
final class Suppressions extends Screen
{
    private const PER_PAGE = 50;

    /** Keys must match SuppressionService::REASONS exactly. */
    private const REASON_TONES = [
        'hard_bounce'           => 'danger',
        'complaint'             => 'danger',
        'unsubscribe'           => 'warn',
        'soft_bounce_threshold' => 'warn',
        'manual_block'          => 'neutral',
        'invalid_address'       => 'neutral',
    ];

    public function render(): void
    {
        $search = $this->input('s');
        $reason = $this->input('reason');
        $page = max(1, $this->intInput('paged', 1));

        [$where, $params] = $this->filters($search, $reason);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM {suppressions} WHERE {$where}", $params);
        $pages = (int) ceil($total / self::PER_PAGE);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = Database::all(
            "SELECT id, normalized_email, reason, source, notes, campaign_id, created_at
               FROM {suppressions}
              WHERE {$where}
           ORDER BY created_at DESC, id DESC
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $params
        );

        View::open(__('Suppressions', 'techzapp-crm'));

        echo '<p class="description">'
            . esc_html__('These addresses are excluded from every campaign automatically. Hard bounces and complaints are added here by Amazon SES feedback, and removing one is rarely the right move.', 'techzapp-crm')
            . '</p>';

        if (Capabilities::canManage()) {
            $this->renderAddForm();
        }

        $this->renderFilters($search, $reason, $total);

        if ($rows === []) {
            View::cardOpen(__('Nothing suppressed', 'techzapp-crm'));
            echo '<p>' . esc_html(
                $search !== '' || $reason !== ''
                    ? __('No suppressions match that filter.', 'techzapp-crm')
                    : __('No addresses have been suppressed yet. That is the healthy state.', 'techzapp-crm')
            ) . '</p>';
            View::cardClose();
            View::close();
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('Email', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Reason', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Source', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Notes', 'techzapp-crm') . '</th>';
        echo '<th>' . esc_html__('Added', 'techzapp-crm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $email = (string) $row['normalized_email'];
            $reasonValue = (string) $row['reason'];

            $actions = '';
            if (Capabilities::canManage()) {
                $actions = sprintf(
                    '<div class="row-actions"><a href="%s" class="submitdelete techzapp-confirm" data-confirm="%s">%s</a></div>',
                    esc_url(View::actionUrl(
                        'techzapp-crm-suppressions',
                        'release',
                        'techzapp_crm_suppression_release',
                        ['email' => $email]
                    )),
                    esc_attr($this->releaseWarning($reasonValue)),
                    esc_html__('Release', 'techzapp-crm')
                );
            }

            printf(
                '<tr><td><strong>%s</strong>%s</td><td>%s</td><td>%s</td><td class="description">%s</td><td>%s</td></tr>',
                esc_html($email),
                $actions,
                View::badge($reasonValue, self::REASON_TONES[$reasonValue] ?? 'neutral'),
                esc_html((string) $row['source']),
                esc_html((string) $row['notes']),
                esc_html(View::dateTime((string) $row['created_at']))
            );
        }

        echo '</tbody></table>';

        if ($pages > 1) {
            $links = paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => $pages,
                'current'   => $page,
            ]);

            if (is_string($links) && $links !== '') {
                echo '<div class="tablenav bottom"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
            }
        }

        View::close();
    }

    /** @return array{0:string, 1:array<string,mixed>} */
    private function filters(string $search, string $reason): array
    {
        $clauses = ['1 = 1'];
        $params = [];

        if (trim($search) !== '') {
            $clauses[] = 'normalized_email LIKE :search';
            $params['search'] = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($search)) . '%';
        }

        if ($reason !== '') {
            // An unknown reason must match nothing; returning every row would
            // quietly answer a different question than the one asked.
            $clauses[] = in_array($reason, SuppressionService::REASONS, true)
                ? 'reason = :reason'
                : '1 = 0';

            if (in_array($reason, SuppressionService::REASONS, true)) {
                $params['reason'] = $reason;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function renderAddForm(): void
    {
        View::cardOpen(
            __('Suppress an address', 'techzapp-crm'),
            __('Use this when someone asks to be removed outside the unsubscribe link.', 'techzapp-crm')
        );

        echo '<form method="post" class="techzapp-inline-form">';
        View::formFields('techzapp-crm-suppressions', 'add', 'techzapp_crm_suppression_add');

        printf(
            '<input type="email" name="email" class="regular-text" placeholder="%s" required> ',
            esc_attr__('someone@example.com', 'techzapp-crm')
        );
        printf(
            '<input type="text" name="notes" class="regular-text" placeholder="%s" maxlength="500"> ',
            esc_attr__('Reason for your records (optional)', 'techzapp-crm')
        );
        submit_button(__('Suppress', 'techzapp-crm'), 'secondary', 'submit', false);

        echo '</form>';
        View::cardClose();
    }

    private function renderFilters(string $search, string $reason, int $total): void
    {
        echo '<form method="get" class="techzapp-search-form">';
        echo '<input type="hidden" name="page" value="techzapp-crm-suppressions">';
        echo '<div class="techzapp-filters">';

        echo '<select name="reason"><option value="">' . esc_html__('All reasons', 'techzapp-crm') . '</option>';
        foreach (SuppressionService::REASONS as $candidate) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($candidate),
                selected($reason, $candidate, false),
                esc_html($candidate)
            );
        }
        echo '</select>';

        printf(
            '<input type="search" name="s" value="%s" placeholder="%s">',
            esc_attr($search),
            esc_attr__('Search email', 'techzapp-crm')
        );

        submit_button(__('Filter', 'techzapp-crm'), '', '', false);
        echo '</div></form>';

        printf(
            '<p class="description">%s</p>',
            esc_html(sprintf(
                /* translators: %s: number of suppressed addresses */
                _n('%s suppressed address.', '%s suppressed addresses.', $total, 'techzapp-crm'),
                View::number($total)
            ))
        );
    }

    private function releaseWarning(string $reason): string
    {
        return match ($reason) {
            'hard_bounce' => __('This address hard-bounced: the mailbox does not exist. Sending to it again damages your sending reputation. Release anyway?', 'techzapp-crm'),
            'complaint'   => __('This recipient marked your email as spam. Sending to them again risks your SES account. Release anyway?', 'techzapp-crm'),
            'unsubscribe' => __('This recipient unsubscribed. Only release if they have asked to resubscribe. Release anyway?', 'techzapp-crm'),
            default       => __('Remove this address from the suppression list?', 'techzapp-crm'),
        };
    }

    public function handle(string $action): void
    {
        match ($action) {
            'add'     => $this->handleAdd(),
            'release' => $this->handleRelease(),
            default   => null,
        };
    }

    private function handleAdd(): void
    {
        $this->authorise('techzapp_crm_suppression_add');

        $email = $this->input('email');
        if ($email === '' || !EmailAddress::isValid($email)) {
            throw CrmException::validation(['email' => __('Enter a valid email address.', 'techzapp-crm')]);
        }

        $added = SuppressionService::suppress(
            $email,
            'manual_block',
            'admin',
            null,
            $this->input('notes'),
            Capabilities::currentUserId()
        );

        // suppress() returns false both when a stronger reason already covers
        // the address and when it rejected the call outright, so the state is
        // read back rather than inferred — reporting success for a no-op would
        // leave the operator believing someone had been excluded.
        if (!$added && !SuppressionService::isSuppressed($email)) {
            throw CrmException::unprocessable(sprintf(
                /* translators: %s: email address */
                __('%s could not be suppressed. Nothing was changed.', 'techzapp-crm'),
                $email
            ));
        }

        $this->redirect(
            'techzapp-crm-suppressions',
            [],
            $added
                ? sprintf(
                    /* translators: %s: email address */
                    __('%s will no longer receive any campaign.', 'techzapp-crm'),
                    $email
                )
                : sprintf(
                    /* translators: %s: email address */
                    __('%s was already suppressed for a stronger reason, which was kept.', 'techzapp-crm'),
                    $email
                ),
            $added ? 'success' : 'info'
        );
    }

    private function handleRelease(): void
    {
        $this->authorise('techzapp_crm_suppression_release');

        $email = $this->input('email');
        if ($email === '') {
            throw CrmException::validation(['email' => __('No address given.', 'techzapp-crm')]);
        }

        $released = SuppressionService::release(
            $email,
            Capabilities::currentUserId(),
            __('Released from the admin screen.', 'techzapp-crm')
        );

        if (!$released) {
            throw CrmException::notFound(__('That address was not on the suppression list.', 'techzapp-crm'));
        }

        $this->redirect(
            'techzapp-crm-suppressions',
            [],
            sprintf(
                /* translators: %s: email address */
                __('%s was released and can receive campaigns again.', 'techzapp-crm'),
                $email
            )
        );
    }
}
