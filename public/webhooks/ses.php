<?php

declare(strict_types=1);

/**
 * Amazon SNS endpoint for SES event publishing.
 *
 * The handler does as little as possible: verify the message is genuinely from
 * SNS, confirm it came from an allowed topic, store the raw payload, and return
 * 200. Parsing happens later in the event cron, so a slow or failing parser
 * never causes SNS to retry-storm this endpoint or drop an event.
 */

require dirname(__DIR__) . '/_bootstrap.php';

use Aws\Sns\Message as SnsMessage;
use Aws\Sns\MessageValidator;
use Techzapp\Crm\Bootstrap;
use Techzapp\Crm\Core\Config;
use Techzapp\Crm\Core\Logger;
use Techzapp\Crm\Services\EventService;

$respond = static function (int $status, string $message): never {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo $message . "\n";
    exit;
};

$config = Bootstrap::init('webhook');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(405, 'Method not allowed.');
}

// SNS payloads are small; anything larger is not from SNS.
$raw = (string) file_get_contents('php://input', false, null, 0, 512 * 1024);
if ($raw === '') {
    $respond(400, 'Empty body.');
}

$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['Type'])) {
    $respond(400, 'Malformed notification.');
}

try {
    // --- authenticity -------------------------------------------------------
    if ($config->bool('sns.verify_signature', true)) {
        $validator = new MessageValidator();
        $validator->validate(new SnsMessage($payload));
    }

    // --- topic allow-list ---------------------------------------------------
    $allowedTopics = array_filter($config->array('sns.allowed_topic_arns', []));
    $topicArn = (string) ($payload['TopicArn'] ?? '');

    if ($allowedTopics !== [] && !in_array($topicArn, $allowedTopics, true)) {
        Logger::warning('webhook.topic_not_allowed', ['topic' => $topicArn]);
        $respond(403, 'Topic not allowed.');
    }

    $type = (string) $payload['Type'];

    if ($type === 'SubscriptionConfirmation') {
        if (!$config->bool('sns.auto_confirm_subscription', true)) {
            Logger::info('webhook.subscription_pending_manual_confirmation', [
                'topic'       => $topicArn,
                'subscribe_url' => (string) ($payload['SubscribeURL'] ?? ''),
            ]);
            $respond(200, 'Subscription recorded; confirm manually.');
        }

        $subscribeUrl = (string) ($payload['SubscribeURL'] ?? '');
        $host = (string) parse_url($subscribeUrl, PHP_URL_HOST);

        // Only ever fetch a confirmation URL on an Amazon SNS host.
        if (preg_match('/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/i', $host) !== 1) {
            Logger::warning('webhook.bad_subscribe_url', ['host' => $host]);
            $respond(400, 'Invalid subscribe URL.');
        }

        $context = stream_context_create(['http' => ['timeout' => 10, 'method' => 'GET']]);
        $result = @file_get_contents($subscribeUrl, false, $context);

        Logger::info('webhook.subscription_confirmed', [
            'topic'   => $topicArn,
            'success' => $result !== false,
        ]);

        $respond(200, $result === false ? 'Confirmation request failed.' : 'Subscription confirmed.');
    }

    if ($type === 'UnsubscribeConfirmation') {
        Logger::warning('webhook.unsubscribe_confirmation', ['topic' => $topicArn]);
        $respond(200, 'Noted.');
    }

    if ($type !== 'Notification') {
        $respond(400, 'Unsupported message type.');
    }

    $messageId = (string) ($payload['MessageId'] ?? '');
    if ($messageId === '') {
        $respond(400, 'Missing MessageId.');
    }

    // UNIQUE(source, message_id): an SNS redelivery is stored once, so the
    // report counts cannot be inflated by duplicate notifications.
    $queued = EventService::queueNotification($messageId, $topicArn, $raw);

    $respond(200, $queued ? 'Accepted.' : 'Duplicate ignored.');
} catch (\Aws\Sns\Exception\InvalidSnsMessageException $e) {
    Logger::warning('webhook.invalid_signature', ['error' => $e->getMessage()]);
    $respond(403, 'Signature verification failed.');
} catch (Throwable $e) {
    Logger::exception('webhook.failed', $e);
    // 500 makes SNS retry, which is what we want if our storage failed.
    $respond(500, 'Temporary failure.');
}
