<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Support;

use Techzapp\Crm\Services\Mail\MailTransport;
use Techzapp\Crm\Services\Mail\Message;
use Techzapp\Crm\Services\Mail\SendResult;
use Techzapp\Crm\Support\EmailAddress;

/**
 * Test transport: records every message instead of sending it, and can be told
 * to return any SendResult so failure paths are exercised deterministically.
 */
final class RecordingTransport implements MailTransport
{
    /** @var list<Message> */
    public array $sent = [];

    /** @var (callable(Message):SendResult)|null */
    public $resultFactory = null;

    /** @var list<string>|null null means "every identity is usable" */
    public ?array $usableIdentities = null;

    /** @var array<string,mixed>|null */
    public ?array $accountStatus = [
        'sending_enabled'    => true,
        'production_access'  => true,
        'enforcement_status' => 'HEALTHY',
        'max_24_hour'        => 50000.0,
        'sent_last_24_hours' => 10.0,
        'max_send_rate'      => 14.0,
    ];

    public function name(): string
    {
        return 'recording';
    }

    public function send(Message $message): SendResult
    {
        $this->sent[] = $message;

        if ($this->resultFactory !== null) {
            return ($this->resultFactory)($message);
        }

        return SendResult::accepted('test-' . bin2hex(random_bytes(10)));
    }

    public function accountStatus(): ?array
    {
        return $this->accountStatus;
    }

    public function isIdentityUsable(string $email): bool
    {
        if ($this->usableIdentities === null) {
            return true;
        }

        $normalized = EmailAddress::normalize($email);
        $domain = EmailAddress::domain($normalized);

        foreach ($this->usableIdentities as $identity) {
            $identity = strtolower($identity);
            if ($identity === $normalized || $identity === $domain) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_map(static fn (Message $message): string => $message->toEmail, $this->sent);
    }
}
