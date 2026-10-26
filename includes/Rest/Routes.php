<?php

declare(strict_types=1);

namespace TechzappCrm\Rest;

use TechzappCrm\Core\Config;
use TechzappCrm\Core\Database;
use TechzappCrm\Core\Logger;
use TechzappCrm\Core\Settings;
use TechzappCrm\Services\EventService;
use TechzappCrm\Services\SuppressionService;
use TechzappCrm\Services\TrackingService;
use TechzappCrm\Support\EmailHtml;
use TechzappCrm\Support\Tokens;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The endpoints recipients and Amazon reach, not the admin.
 *
 * All four are intentionally public (`__return_true`): a recipient must be
 * able to unsubscribe without a WordPress account, a mail client fetches the
 * pixel with no session, and SNS posts from AWS. Authorisation is instead the
 * opaque token plus an HMAC bound to the purpose and every parameter, which is
 * what the standalone app used and is unchanged here.
 */
final class Routes
{
    public const NAMESPACE = 'techzapp-crm/v1';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        $public = '__return_true';

        register_rest_route(self::NAMESPACE, '/t/o', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'openPixel'],
            'permission_callback' => $public,
            'args'                => [
                'r' => ['required' => true, 'type' => 'string'],
                's' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/t/c', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'clickRedirect'],
            'permission_callback' => $public,
            'args'                => [
                'r' => ['required' => true, 'type' => 'string'],
                'l' => ['required' => true, 'type' => 'string'],
                's' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/u', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'unsubscribePage'],
                'permission_callback' => $public,
            ],
            [
                // Also the List-Unsubscribe-Post target (RFC 8058).
                'methods'             => 'POST',
                'callback'            => [self::class, 'unsubscribeSubmit'],
                'permission_callback' => $public,
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/webhooks/ses', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'snsWebhook'],
            'permission_callback' => $public,
        ]);
    }

    // ------------------------------------------------------------ URL helpers

    public static function trackingUrl(string $path): string
    {
        return rest_url(self::NAMESPACE . $path);
    }

    public static function webhookUrl(): string
    {
        return rest_url(self::NAMESPACE . '/webhooks/ses');
    }

    // ----------------------------------------------------------- open pixel

    /**
     * Always returns a 1x1 GIF, whatever happened.
     *
     * An invalid token must be indistinguishable from a valid one, or the
     * endpoint becomes an oracle for probing which tokens exist.
     */
    public static function openPixel(WP_REST_Request $request): void
    {
        try {
            $ip = self::clientIp();

            if (TrackingService::allowPublicRequest('open', $ip)) {
                $recipient = TrackingService::resolveRecipient(
                    (string) $request->get_param('r'),
                    (string) $request->get_param('s'),
                    TrackingService::PURPOSE_OPEN
                );

                if ($recipient !== null && (int) $recipient['track_opens'] === 1) {
                    TrackingService::recordOpen($recipient, self::userAgent(), $ip);
                }
            }
        } catch (Throwable $e) {
            // A tracking failure must never render an error inside an email.
            self::logQuietly('tracking.open_failed', $e);
        }

        self::sendPixel();
    }

    private static function sendPixel(): never
    {
        $pixel = TrackingService::pixelBytes();

        if (!headers_sent()) {
            header('Content-Type: image/gif');
            header('Content-Length: ' . strlen($pixel));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }

        echo $pixel; // phpcs:ignore WordPress.Security.EscapeOutput -- binary GIF
        exit;
    }

    // --------------------------------------------------------- click redirect

    /**
     * Redirects to a destination looked up by link id.
     *
     * The URL never carries the destination, only an id that was registered
     * when the campaign was built, and the result is validated again before
     * the redirect. There is no input that turns this into an open redirect.
     */
    public static function clickRedirect(WP_REST_Request $request): void
    {
        $destination = null;

        try {
            $linkId = (string) $request->get_param('l');
            $ip = self::clientIp();

            if (ctype_digit($linkId) && TrackingService::allowPublicRequest('click', $ip)) {
                $recipient = TrackingService::resolveRecipient(
                    (string) $request->get_param('r'),
                    (string) $request->get_param('s'),
                    TrackingService::PURPOSE_CLICK,
                    $linkId
                );

                if ($recipient !== null) {
                    $destination = (int) $recipient['track_clicks'] === 1
                        ? TrackingService::recordClick($recipient, (int) $linkId, self::userAgent(), $ip)
                        : null;

                    if ($destination === null) {
                        // Tracking off for this campaign, or the row is gone:
                        // resolve without recording, still validating.
                        $url = Database::scalar(
                            'SELECT url FROM {campaign_links} WHERE id = :id AND campaign_id = :campaign',
                            ['id' => (int) $linkId, 'campaign' => (int) $recipient['campaign_id']]
                        );
                        $destination = is_string($url) && EmailHtml::isSafeRedirectTarget($url) ? $url : null;
                    }
                }
            }
        } catch (Throwable $e) {
            self::logQuietly('tracking.click_failed', $e);
        }

        // Nothing we can trust: send the visitor to the site root rather than
        // anywhere derived from their input.
        $target = $destination ?? home_url('/');

        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
            header('X-Content-Type-Options: nosniff');
        }

        wp_redirect($target, 302);
        exit;
    }

    // ------------------------------------------------------------ unsubscribe

    public static function unsubscribePage(WP_REST_Request $request): void
    {
        self::handleUnsubscribe($request, false);
    }

    public static function unsubscribeSubmit(WP_REST_Request $request): void
    {
        self::handleUnsubscribe($request, true);
    }

    private static function handleUnsubscribe(WP_REST_Request $request, bool $isPost): never
    {
        try {
            if (!TrackingService::allowPublicRequest('unsubscribe', self::clientIp())) {
                self::renderPage(
                    __('Too many requests', 'techzapp-crm'),
                    '<h1>' . esc_html__('Too many requests', 'techzapp-crm') . '</h1>'
                    . '<p>' . esc_html__('Please wait a moment and try again.', 'techzapp-crm') . '</p>',
                    429
                );
            }

            $recipientToken = (string) $request->get_param('r');
            $contactToken = (string) $request->get_param('c');
            $signature = (string) $request->get_param('s');

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
                    'SELECT email FROM {contacts} WHERE unsubscribe_token = :token',
                    ['token' => $contactToken]
                );
                $email = $row === null ? null : (string) $row['email'];
            }

            if ($email === null) {
                self::renderPage(
                    __('Link not recognised', 'techzapp-crm'),
                    '<h1>' . esc_html__('This unsubscribe link is not valid', 'techzapp-crm') . '</h1>'
                    . '<p>' . esc_html__('The link may have expired or been altered. If you keep receiving unwanted email, reply to the message and ask to be removed.', 'techzapp-crm') . '</p>',
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
                        'UPDATE {campaign_recipients}
                            SET unsubscribed_at = COALESCE(unsubscribed_at, UTC_TIMESTAMP()),
                                updated_at = :now
                          WHERE id = :id',
                        ['now' => Database::nowUtc(), 'id' => (int) $recipient['id']]
                    );

                    EventService::record(
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

                // One-click clients (Gmail, Apple Mail) expect a plain 200.
                if (strtolower((string) $request->get_param('List-Unsubscribe')) === 'one-click'
                    || ($request->get_body_params()['List-Unsubscribe'] ?? '') === 'One-Click') {
                    if (!headers_sent()) {
                        status_header(200);
                        header('Content-Type: text/plain; charset=utf-8');
                    }
                    echo "Unsubscribed.\n";
                    exit;
                }

                self::renderPage(
                    __('Unsubscribed', 'techzapp-crm'),
                    '<h1>' . esc_html__('You have been unsubscribed', 'techzapp-crm') . '</h1>'
                    . '<p class="ok">' . esc_html(sprintf(
                        /* translators: %s: partially hidden email address */
                        __('%s will no longer receive marketing email from us.', 'techzapp-crm'),
                        self::maskEmail($email)
                    )) . '</p>'
                    . '<p>' . esc_html__('This applies to every list and campaign, not just this message.', 'techzapp-crm') . '</p>'
                );
            }

            if (SuppressionService::isSuppressed($email)) {
                self::renderPage(
                    __('Already unsubscribed', 'techzapp-crm'),
                    '<h1>' . esc_html__('You are already unsubscribed', 'techzapp-crm') . '</h1>'
                    . '<p class="ok">' . esc_html(sprintf(
                        /* translators: %s: partially hidden email address */
                        __('%s is not on any of our mailing lists.', 'techzapp-crm'),
                        self::maskEmail($email)
                    )) . '</p>'
                );
            }

            $action = esc_url(add_query_arg(
                array_filter([
                    'r' => $recipientToken ?: null,
                    'c' => $contactToken ?: null,
                    's' => $signature,
                ]),
                self::trackingUrl('/u')
            ));

            self::renderPage(
                __('Unsubscribe', 'techzapp-crm'),
                '<h1>' . esc_html__('Unsubscribe from these emails', 'techzapp-crm') . '</h1>'
                . '<p>' . esc_html(sprintf(
                    /* translators: %s: partially hidden email address */
                    __('Confirm that %s should stop receiving marketing email from us.', 'techzapp-crm'),
                    self::maskEmail($email)
                )) . '</p>'
                . '<form method="post" action="' . $action . '">'
                . '<button type="submit">' . esc_html__('Unsubscribe me', 'techzapp-crm') . '</button>'
                . '</form>'
                . '<p class="muted">' . esc_html__('This removes you from every list and campaign. It takes effect immediately.', 'techzapp-crm') . '</p>'
            );
        } catch (Throwable $e) {
            self::logQuietly('unsubscribe.failed', $e);
            self::renderPage(
                __('Something went wrong', 'techzapp-crm'),
                '<h1>' . esc_html__('We could not complete that right now', 'techzapp-crm') . '</h1>'
                . '<p>' . esc_html__('Please try again in a few minutes.', 'techzapp-crm') . '</p>',
                500
            );
        }
    }

    // --------------------------------------------------------- SNS webhook

    /**
     * Stores an SNS notification and returns, nothing more.
     *
     * Parsing happens later in the event job, so a slow or failing parser
     * never causes SNS to retry-storm this endpoint or drop an event.
     */
    public static function snsWebhook(WP_REST_Request $request): WP_REST_Response
    {
        $raw = $request->get_body();

        if ($raw === '' || strlen($raw) > 512 * 1024) {
            return new WP_REST_Response(['message' => 'Malformed notification.'], 400);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['Type'])) {
            return new WP_REST_Response(['message' => 'Malformed notification.'], 400);
        }

        try {
            if (Settings::bool('sns_verify_signature', true)) {
                if (!class_exists(\Aws\Sns\MessageValidator::class)) {
                    Logger::error('webhook.validator_missing');
                    return new WP_REST_Response(['message' => 'Signature validation unavailable.'], 500);
                }

                (new \Aws\Sns\MessageValidator())->validate(new \Aws\Sns\Message($payload));
            }

            $allowedTopics = array_filter(array_map('strval', Settings::array('sns_allowed_topic_arns')));
            $topicArn = (string) ($payload['TopicArn'] ?? '');

            if ($allowedTopics !== [] && !in_array($topicArn, $allowedTopics, true)) {
                Logger::warning('webhook.topic_not_allowed', ['topic' => $topicArn]);
                return new WP_REST_Response(['message' => 'Topic not allowed.'], 403);
            }

            $type = (string) $payload['Type'];

            if ($type === 'SubscriptionConfirmation') {
                return self::confirmSubscription($payload, $topicArn);
            }

            if ($type === 'UnsubscribeConfirmation') {
                Logger::warning('webhook.unsubscribe_confirmation', ['topic' => $topicArn]);
                return new WP_REST_Response(['message' => 'Noted.'], 200);
            }

            if ($type !== 'Notification') {
                return new WP_REST_Response(['message' => 'Unsupported message type.'], 400);
            }

            $messageId = (string) ($payload['MessageId'] ?? '');
            if ($messageId === '') {
                return new WP_REST_Response(['message' => 'Missing MessageId.'], 400);
            }

            // UNIQUE(source, message_id): an SNS redelivery is stored once, so
            // the reports cannot be inflated by duplicate notifications.
            $queued = EventService::queueNotification($messageId, $topicArn, $raw);

            return new WP_REST_Response(
                ['message' => $queued ? 'Accepted.' : 'Duplicate ignored.'],
                200
            );
        } catch (\Aws\Sns\Exception\InvalidSnsMessageException $e) {
            Logger::warning('webhook.invalid_signature', ['error' => $e->getMessage()]);
            return new WP_REST_Response(['message' => 'Signature verification failed.'], 403);
        } catch (Throwable $e) {
            Logger::exception('webhook.failed', $e);
            // 500 makes SNS retry, which is what we want if storage failed.
            return new WP_REST_Response(['message' => 'Temporary failure.'], 500);
        }
    }

    private static function confirmSubscription(array $payload, string $topicArn): WP_REST_Response
    {
        $subscribeUrl = (string) ($payload['SubscribeURL'] ?? '');
        $host = (string) wp_parse_url($subscribeUrl, PHP_URL_HOST);

        // Only ever fetch a confirmation URL on an Amazon SNS host.
        if (preg_match('/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/i', $host) !== 1) {
            Logger::warning('webhook.bad_subscribe_url', ['host' => $host]);
            return new WP_REST_Response(['message' => 'Invalid subscribe URL.'], 400);
        }

        $response = wp_remote_get($subscribeUrl, ['timeout' => 10]);
        $ok = !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 400;

        Logger::info('webhook.subscription_confirmed', ['topic' => $topicArn, 'success' => $ok]);

        return new WP_REST_Response(
            ['message' => $ok ? 'Subscription confirmed.' : 'Confirmation request failed.'],
            200
        );
    }

    // ---------------------------------------------------------------- helpers

    /** Renders a small self-contained page: no theme, no assets, no tracking. */
    /**
     * Markup permitted on the public pages.
     *
     * Deliberately tiny, and a superset of wp_kses_post only in the one place
     * it has to be: the confirmation form.
     *
     * @return array<string,array<string,bool>>
     */
    private static function allowedPageHtml(): array
    {
        return [
            'h1'     => [],
            'p'      => ['class' => true],
            'strong' => [],
            'em'     => [],
            'br'     => [],
            'form'   => ['method' => true, 'action' => true],
            'button' => ['type' => true, 'class' => true],
            'a'      => ['href' => true, 'rel' => true],
        ];
    }

    private static function renderPage(string $title, string $body, int $status = 200): never
    {
        if (!headers_sent()) {
            status_header($status);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header('X-Frame-Options: DENY');
            header('Cache-Control: no-store');
        }

        $css = 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:#f8fafc;color:#0f172a;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;padding:24px}'
            . '.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px;max-width:460px;width:100%;'
            . 'box-shadow:0 1px 3px rgba(15,23,42,.08)}'
            . 'h1{font-size:20px;margin:0 0 12px}p{margin:0 0 16px;color:#475569}'
            . 'button{background:#dc2626;color:#fff;border:0;border-radius:8px;padding:12px 20px;font-size:15px;'
            . 'font-weight:600;cursor:pointer;width:100%}button:hover{background:#b91c1c}'
            . '.ok{color:#15803d;font-weight:600}.muted{font-size:13px;color:#94a3b8;margin-top:20px}';

        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . esc_html($title) . '</title>'
            . '<style>' . $css . '</style></head><body><div class="card">'
            // wp_kses_post would strip the form: it is not in WordPress's
            // post allow-list, while <button> is. That left a button with
            // nothing to submit to, so the page rendered correctly and the
            // unsubscribe simply did nothing when clicked.
            . wp_kses($body, self::allowedPageHtml())
            . '</div></body></html>';

        exit;
    }

    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return __('your address', 'techzapp-crm');
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        return substr($local, 0, 1) . str_repeat('*', max(1, min(6, strlen($local) - 1))) . $domain;
    }

    private static function clientIp(): string
    {
        return \TechzappCrm\Core\Audit::clientIp();
    }

    private static function userAgent(): string
    {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])), 0, 255)
            : '';
    }

    private static function logQuietly(string $message, Throwable $e): void
    {
        try {
            Logger::exception($message, $e);
        } catch (Throwable) {
            // Nothing further we can do.
        }
    }
}
