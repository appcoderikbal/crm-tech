<?php

declare(strict_types=1);

/**
 * Unsubscribe landing page and RFC 8058 one-click endpoint.
 *
 * No authentication: recipients must be able to opt out without an account.
 * The URL carries an opaque token plus an HMAC — never a contact id, an email
 * address or anything else that identifies the person to an observer.
 *
 *   GET  → confirmation page
 *   POST → performs the opt-out (also the target of List-Unsubscribe-Post)
 */

require dirname(__DIR__) . '/_bootstrap.php';

use Techzapp\Crm\Bootstrap;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Logger;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Services\SuppressionService;
use Techzapp\Crm\Services\TrackingService;
use Techzapp\Crm\Support\Tokens;

Bootstrap::init('unsubscribe');

$request = Request::capture();
$recipientToken = (string) ($_GET['r'] ?? '');
$contactToken = (string) ($_GET['c'] ?? '');
$signature = (string) ($_GET['s'] ?? '');
$isPost = $request->method === 'POST';

/** Renders a minimal self-contained page; no external assets, no tracking. */
$render = static function (string $title, string $body, int $status = 200): never {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#f8fafc;color:#0f172a;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;padding:24px}'
        . '.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px;max-width:460px;width:100%;'
        . 'box-shadow:0 1px 3px rgba(15,23,42,.08)}'
        . 'h1{font-size:20px;margin:0 0 12px}p{margin:0 0 16px;color:#475569}'
        . 'button{background:#dc2626;color:#fff;border:0;border-radius:8px;padding:12px 20px;font-size:15px;'
        . 'font-weight:600;cursor:pointer;width:100%}button:hover{background:#b91c1c}'
        . '.ok{color:#15803d;font-weight:600}.muted{font-size:13px;color:#94a3b8;margin-top:20px}'
        . '</style></head><body><div class="card">' . $body . '</div></body></html>';
    exit;
};

$maskEmail = static function (string $email): string {
    $at = strpos($email, '@');
    if ($at === false || $at < 1) {
        return 'your address';
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at);
    $visible = substr($local, 0, 1);
    return htmlspecialchars($visible . str_repeat('*', max(1, min(6, strlen($local) - 1))) . $domain, ENT_QUOTES, 'UTF-8');
};

try {
    if (!TrackingService::allowPublicRequest('unsubscribe', $request->ip())) {
        $render('Too many requests', '<h1>Too many requests</h1><p>Please wait a moment and try again.</p>', 429);
    }

    $email = null;
    $recipient = null;
    $campaignId = null;

    if ($recipientToken !== '') {
        $recipient = TrackingService::resolveRecipient(
            $recipientToken,
            $signature,
            TrackingService::PURPOSE_UNSUBSCRIBE
        );
        if ($recipient !== null) {
            $email = (string) $recipient['recipient_email_snapshot'];
            $campaignId = (int) $recipient['campaign_id'];
        }
    } elseif ($contactToken !== '' && Tokens::verify($signature, TrackingService::PURPOSE_UNSUBSCRIBE, 'c:' . $contactToken)) {
        $row = Database::first(
            'SELECT email FROM contacts WHERE unsubscribe_token = :token',
            ['token' => $contactToken]
        );
        $email = $row === null ? null : (string) $row['email'];
    }

    if ($email === null) {
        $render(
            'Link not recognised',
            '<h1>This unsubscribe link is not valid</h1>'
            . '<p>The link may have expired or been altered. If you keep receiving unwanted email, '
            . 'reply to the message and ask to be removed.</p>',
            404
        );
    }

    if ($isPost) {
        SuppressionService::suppress(
            $email,
            'unsubscribe',
            $recipient !== null ? 'one_click_unsubscribe' : 'unsubscribe_page',
            $campaignId,
            'Recipient opted out'
        );

        if ($recipient !== null) {
            Database::execute(
                'UPDATE campaign_recipients
                    SET unsubscribed_at = COALESCE(unsubscribed_at, UTC_TIMESTAMP())
                  WHERE id = :id',
                ['id' => (int) $recipient['id']]
            );
            \Techzapp\Crm\Services\EventService::record(
                campaignId: $campaignId,
                recipientId: (int) $recipient['id'],
                contactId: $recipient['contact_id'] === null ? null : (int) $recipient['contact_id'],
                eventType: 'unsubscribe',
                dedupeKey: hash('sha256', 'unsub|' . $recipient['id']),
                providerEventId: null,
                timestamp: Database::nowUtc(),
                metadata: ['source' => 'landing_page']
            );
        }

        // One-click clients (Gmail, Apple Mail) expect a plain 200, not HTML.
        if (str_contains((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')
            && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo "Unsubscribed.\n";
            exit;
        }

        $render(
            'Unsubscribed',
            '<h1>You have been unsubscribed</h1>'
            . '<p class="ok">' . $maskEmail($email) . ' will no longer receive marketing email from us.</p>'
            . '<p>This applies to every list and campaign, not just this message.</p>'
            . '<p class="muted">If this was a mistake, reply to any previous message and ask to be re-added.</p>'
        );
    }

    $alreadySuppressed = SuppressionService::isSuppressed($email);
    if ($alreadySuppressed) {
        $render(
            'Already unsubscribed',
            '<h1>You are already unsubscribed</h1>'
            . '<p class="ok">' . $maskEmail($email) . ' is not on any of our mailing lists.</p>'
            . '<p class="muted">No further action is needed.</p>'
        );
    }

    $action = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/u/', ENT_QUOTES, 'UTF-8');
    $render(
        'Unsubscribe',
        '<h1>Unsubscribe from these emails</h1>'
        . '<p>Confirm that <strong>' . $maskEmail($email) . '</strong> should stop receiving marketing email from us.</p>'
        . '<form method="post" action="' . $action . '">'
        . '<button type="submit">Unsubscribe me</button></form>'
        . '<p class="muted">This removes you from every list and campaign. It takes effect immediately.</p>'
    );
} catch (Throwable $e) {
    try {
        Logger::exception('unsubscribe.failed', $e);
    } catch (Throwable) {
        // nothing further we can do
    }
    $render(
        'Something went wrong',
        '<h1>We could not complete that right now</h1>'
        . '<p>Please try again in a few minutes. If the problem continues, reply to the email you received.</p>',
        500
    );
}
