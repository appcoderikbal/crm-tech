<?php

declare(strict_types=1);

/**
 * Open-tracking pixel.
 *
 * Always returns a 1x1 GIF, whatever happens — an invalid or expired token must
 * not be distinguishable from a valid one, or the endpoint becomes an oracle
 * for probing which tokens exist.
 */

require dirname(__DIR__) . '/_bootstrap.php';

use Techzapp\Crm\Bootstrap;
use Techzapp\Crm\Core\Logger;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Services\TrackingService;

$pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true) ?: '';

$emitPixel = static function () use ($pixel): never {
    if (!headers_sent()) {
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($pixel));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }
    echo $pixel;
    exit;
};

try {
    Bootstrap::init('tracking');

    $request = Request::capture();
    $token = (string) ($_GET['r'] ?? '');
    $signature = (string) ($_GET['s'] ?? '');

    if (!TrackingService::allowPublicRequest('open', $request->ip())) {
        $emitPixel();
    }

    $recipient = TrackingService::resolveRecipient($token, $signature, TrackingService::PURPOSE_OPEN);

    if ($recipient !== null && (int) $recipient['track_opens'] === 1) {
        TrackingService::recordOpen($recipient, $request->userAgent(), $request->ip());
    }
} catch (Throwable $e) {
    // A tracking failure must never produce an error page inside an email.
    try {
        Logger::exception('tracking.open_failed', $e);
    } catch (Throwable) {
        // nothing further we can do
    }
}

$emitPixel();
