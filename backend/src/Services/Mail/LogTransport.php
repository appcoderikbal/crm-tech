<?php

declare(strict_types=1);

namespace Techzapp\Crm\Services\Mail;

use Techzapp\Crm\Core\Config;

/**
 * Writes messages to storage/logs/mail.log and sends nothing.
 *
 * Used for local development and staging rehearsals. Selecting it in
 * production would silently stop all mail, so the Settings screen surfaces the
 * active transport.
 */
final class LogTransport implements MailTransport
{
    public function __construct(private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'log';
    }

    public function send(Message $message): SendResult
    {
        $directory = $this->config->string('storage.logs', dirname(__DIR__, 3) . '/storage/logs');
        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        $entry = [
            'at'      => gmdate('c'),
            'to'      => $message->toEmail,
            'from'    => $message->fromHeader(),
            'subject' => $message->safeSubject(),
            'headers' => $message->safeHeaders(),
            'tags'    => $message->safeTags(),
            'text'    => mb_substr($message->textBody, 0, 500),
            'html_bytes' => strlen($message->htmlBody),
        ];

        @file_put_contents(
            $directory . '/mail.log',
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );

        return SendResult::accepted('log-' . bin2hex(random_bytes(12)));
    }

    public function accountStatus(): ?array
    {
        return [
            'sending_enabled'    => true,
            'production_access'  => false,
            'enforcement_status' => 'LOG_TRANSPORT',
            'max_24_hour'        => 0.0,
            'sent_last_24_hours' => 0.0,
            'max_send_rate'      => 0.0,
        ];
    }

    public function isIdentityUsable(string $email): bool
    {
        return $email !== '';
    }
}
