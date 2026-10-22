<?php

declare(strict_types=1);

namespace Techzapp\Crm\Services\Mail;

use Techzapp\Crm\Support\EmailAddress;

/** A single outbound message, fully rendered for one recipient. */
final class Message
{
    /**
     * @param array<string,string> $headers extra headers (List-Unsubscribe, …)
     * @param array<string,string> $tags    SES message tags (campaign/recipient ids)
     */
    public function __construct(
        public readonly string $toEmail,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $replyTo,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly string $textBody,
        public readonly array $headers = [],
        public readonly array $tags = [],
        public readonly string $configurationSet = ''
    ) {
    }

    public function fromHeader(): string
    {
        return EmailAddress::formatSender($this->fromName, $this->fromEmail);
    }

    /** @return array<string,string> Header values with CR/LF stripped. */
    public function safeHeaders(): array
    {
        $safe = [];
        foreach ($this->headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9\-]{1,80}$/', $name) !== 1) {
                continue;
            }
            $clean = EmailAddress::sanitizeHeaderValue((string) $value);
            if ($clean !== '') {
                $safe[$name] = substr($clean, 0, 2000);
            }
        }
        return $safe;
    }

    /**
     * SES tag names/values accept only alphanumerics, dashes and underscores.
     *
     * @return array<string,string>
     */
    public function safeTags(): array
    {
        $safe = [];
        foreach ($this->tags as $name => $value) {
            $cleanName = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $name) ?? '';
            $cleanValue = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $value) ?? '';
            if ($cleanName !== '' && $cleanValue !== '') {
                $safe[substr($cleanName, 0, 256)] = substr($cleanValue, 0, 256);
            }
        }
        return $safe;
    }

    /** Subject lines must not be able to inject additional headers. */
    public function safeSubject(): string
    {
        return substr(EmailAddress::sanitizeHeaderValue($this->subject), 0, 900);
    }
}
