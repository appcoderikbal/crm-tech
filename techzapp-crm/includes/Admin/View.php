<?php

declare(strict_types=1);

namespace TechzappCrm\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small rendering helpers shared by the admin screens.
 *
 * Everything echoed here is escaped at the point of output. The helpers exist
 * so that is consistent rather than remembered.
 */
final class View
{
    /** Opens a standard admin wrap with a title and optional header action. */
    public static function open(string $title, ?string $actionUrl = null, ?string $actionLabel = null): void
    {
        echo '<div class="wrap techzapp-crm">';
        echo '<h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';

        if ($actionUrl !== null && $actionLabel !== null) {
            printf(
                ' <a href="%s" class="page-title-action">%s</a>',
                esc_url($actionUrl),
                esc_html($actionLabel)
            );
        }

        echo '<hr class="wp-header-end">';
        Notices::renderQueued();
    }

    public static function close(): void
    {
        echo '</div>';
    }

    /** @param 'success'|'error'|'warning'|'info' $type */
    public static function notice(string $message, string $type = 'info', bool $dismissible = false): void
    {
        printf(
            '<div class="notice notice-%s%s"><p>%s</p></div>',
            esc_attr($type),
            $dismissible ? ' is-dismissible' : '',
            wp_kses($message, self::allowedNoticeHtml())
        );
    }

    /** @return array<string,array<string,bool>> */
    public static function allowedNoticeHtml(): array
    {
        return [
            'a'      => ['href' => true, 'class' => true],
            'strong' => [],
            'em'     => [],
            'code'   => [],
            'br'     => [],
        ];
    }

    /** The sub-navigation shown under a section's heading. */
    public static function tabs(array $tabs, string $current): void
    {
        echo '<nav class="nav-tab-wrapper wp-clearfix">';
        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(Menu::url($slug)),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
    }

    /**
     * A row of headline numbers.
     *
     * @param list<array{label:string,value:string,hint?:string,tone?:string,url?:string}> $tiles
     */
    public static function statTiles(array $tiles): void
    {
        echo '<div class="techzapp-tiles">';

        foreach ($tiles as $tile) {
            $tone = $tile['tone'] ?? 'neutral';
            $inner = '<span class="techzapp-tile__label">' . esc_html($tile['label']) . '</span>'
                . '<span class="techzapp-tile__value tone-' . esc_attr($tone) . '">' . esc_html($tile['value']) . '</span>'
                . (isset($tile['hint']) && $tile['hint'] !== ''
                    ? '<span class="techzapp-tile__hint">' . esc_html($tile['hint']) . '</span>'
                    : '');

            if (isset($tile['url'])) {
                printf('<a class="techzapp-tile" href="%s">%s</a>', esc_url($tile['url']), $inner);
            } else {
                printf('<div class="techzapp-tile">%s</div>', $inner);
            }
        }

        echo '</div>';
    }

    /** A coloured status pill. */
    public static function badge(string $label, string $tone = 'neutral'): string
    {
        return sprintf(
            '<span class="techzapp-badge tone-%s">%s</span>',
            esc_attr($tone),
            esc_html($label)
        );
    }

    /** Green / amber / red / grey indicator. */
    public static function statusDot(string $status): string
    {
        return sprintf('<span class="techzapp-dot status-%s" aria-hidden="true"></span>', esc_attr($status));
    }

    public static function cardOpen(string $title, string $description = ''): void
    {
        echo '<div class="techzapp-card"><div class="techzapp-card__head">';
        echo '<h2>' . esc_html($title) . '</h2>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</div><div class="techzapp-card__body">';
    }

    public static function cardClose(): void
    {
        echo '</div></div>';
    }

    /** Formats a stored UTC timestamp in the site's timezone. */
    public static function dateTime(?string $utc): string
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return '—';
        }

        $timestamp = strtotime($utc . ' UTC');
        if ($timestamp === false) {
            return '—';
        }

        return wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            $timestamp
        ) ?: '—';
    }

    /** "3 minutes ago", or a dash. */
    public static function relative(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }

        $timestamp = strtotime($utc . ' UTC');
        if ($timestamp === false) {
            return '—';
        }

        if ($timestamp > time()) {
            /* translators: %s: human-readable time difference */
            return sprintf(__('in %s', 'techzapp-crm'), human_time_diff(time(), $timestamp));
        }

        /* translators: %s: human-readable time difference */
        return sprintf(__('%s ago', 'techzapp-crm'), human_time_diff($timestamp, time()));
    }

    public static function number(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        return number_format_i18n((float) $value);
    }

    /**
     * A percentage, or an em dash when there was nothing to divide by.
     *
     * Null is not zero: "no data" and "zero percent" mean different things on
     * a deliverability screen, and showing 0% for an unknown would be a lie.
     */
    public static function percent(?float $value, int $decimals = 2): string
    {
        if ($value === null) {
            return '—';
        }
        return number_format_i18n($value, $decimals) . '%';
    }

    /** Minutes as a human phrase. */
    public static function duration(int $minutes): string
    {
        if ($minutes <= 0) {
            return __('less than a minute', 'techzapp-crm');
        }
        if ($minutes < 60) {
            /* translators: %s: number of minutes */
            return sprintf(_n('%s minute', '%s minutes', $minutes, 'techzapp-crm'), number_format_i18n($minutes));
        }

        $hours = round($minutes / 60, 1);
        if ($hours < 24) {
            /* translators: %s: number of hours */
            return sprintf(__('about %s hours', 'techzapp-crm'), number_format_i18n($hours, 1));
        }

        /* translators: %s: number of days */
        return sprintf(__('about %s days', 'techzapp-crm'), number_format_i18n(round($hours / 24, 1), 1));
    }

    public static function bytes(int $bytes): string
    {
        return size_format($bytes) ?: '0 B';
    }

    /** Hidden fields every plugin form needs: page, action and nonce. */
    public static function formFields(string $page, string $action, string $nonceAction): void
    {
        printf('<input type="hidden" name="page" value="%s">', esc_attr($page));
        printf('<input type="hidden" name="crm_action" value="%s">', esc_attr($action));
        wp_nonce_field($nonceAction);
    }

    /** A URL carrying a nonce, for destructive row actions. */
    public static function actionUrl(string $page, string $action, string $nonceAction, array $args = []): string
    {
        return wp_nonce_url(
            Menu::url($page, ['crm_action' => $action] + $args),
            $nonceAction
        );
    }
}
