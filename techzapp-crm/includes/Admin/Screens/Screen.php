<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\Menu;
use TechzappCrm\Admin\Notices;
use TechzappCrm\Core\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/** Base class for every admin screen. */
abstract class Screen
{
    /** Renders the page body. */
    abstract public function render(): void;

    /**
     * Handles a form submission or row action before any output.
     * Screens that accept actions override this.
     */
    public function handle(string $action): void
    {
    }

    /** Reads a sanitised GET/POST scalar. */
    protected function input(string $key, string $default = ''): string
    {
        if (!isset($_REQUEST[$key])) {
            return $default;
        }

        return sanitize_text_field(wp_unslash((string) $_REQUEST[$key]));
    }

    protected function intInput(string $key, int $default = 0): int
    {
        $value = $this->input($key, '');
        return $value === '' ? $default : (int) $value;
    }

    /** Reads a list of integer ids from a bulk-action checkbox field. */
    protected function idList(string $key): array
    {
        if (!isset($_REQUEST[$key]) || !is_array($_REQUEST[$key])) {
            return [];
        }

        $ids = array_map('intval', wp_unslash($_REQUEST[$key]));
        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /** Verifies the nonce and the write capability, in that order. */
    protected function authorise(string $nonceAction, bool $settings = false): void
    {
        Capabilities::checkNonce($nonceAction);

        if ($settings) {
            Capabilities::requireSettings();
        } else {
            Capabilities::requireManage();
        }
    }

    /** Queues a message and redirects, so a refresh cannot resubmit the form. */
    protected function redirect(string $page, array $args = [], string $message = '', string $type = 'success'): never
    {
        if ($message !== '') {
            Notices::queue($message, $type);
        }

        wp_safe_redirect(Menu::url($page, $args));
        exit;
    }
}
