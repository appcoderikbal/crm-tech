<?php

declare(strict_types=1);

/**
 * Click-tracking redirect.
 *
 * The destination is never taken from the URL. The link id is looked up in
 * campaign_links, which only ever contains URLs extracted from the approved
 * campaign body, and the result is re-validated before the redirect is issued.
 * There is therefore no input an attacker can supply that turns this endpoint
 * into an open redirect.
 */

require dirname(__DIR__) . '/_bootstrap.php';

use Techzapp\Crm\Bootstrap;
use Techzapp\Crm\Core\Config;
use Techzapp\Crm\Core\Logger;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Services\TrackingService;
use Techzapp\Crm\Support\EmailHtml;

$fallback = static function (string $reason): never {
    // No destination we can trust: send the visitor to the application root
    // rather than anywhere derived from their input.
    $home = Config::load()->appUrl();
    if (!headers_sent()) {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Location: ' . ($home !== '' ? $home : '/'), true, 302);
    }
    echo '<!doctype html><meta charset="utf-8"><title>Link unavailable</title>'
        . '<p>This link is no longer available.</p>';
    exit;
};

try {
    Bootstrap::init('tracking');

    $request = Request::capture();
    $token = (string) ($_GET['r'] ?? '');
    $signature = (string) ($_GET['s'] ?? '');
    $linkId = (string) ($_GET['l'] ?? '');

    if (!ctype_digit($linkId)) {
        $fallback('invalid_link_id');
    }
    if (!TrackingService::allowPublicRequest('click', $request->ip())) {
        $fallback('rate_limited');
    }

    $recipient = TrackingService::resolveRecipient(
        $token,
        $signature,
        TrackingService::PURPOSE_CLICK,
        $linkId
    );

    if ($recipient === null) {
        $fallback('invalid_token');
    }

    $url = (int) $recipient['track_clicks'] === 1
        ? TrackingService::recordClick($recipient, (int) $linkId, $request->userAgent(), $request->ip())
        : null;

    if ($url === null) {
        // Tracking is off for this campaign, or the link row is gone: resolve
        // the destination without recording, still validating it.
        $url = \Techzapp\Crm\Core\Database::scalar(
            'SELECT url FROM campaign_links WHERE id = :id AND campaign_id = :campaign',
            ['id' => (int) $linkId, 'campaign' => (int) $recipient['campaign_id']]
        );
        $url = is_string($url) && EmailHtml::isSafeRedirectTarget($url) ? $url : null;
    }

    if ($url === null) {
        $fallback('no_destination');
    }

    if (!headers_sent()) {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Location: ' . $url, true, 302);
    }
    exit;
} catch (Throwable $e) {
    try {
        Logger::exception('tracking.click_failed', $e);
    } catch (Throwable) {
        // nothing further we can do
    }
    $fallback('exception');
}
