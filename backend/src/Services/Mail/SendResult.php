<?php

declare(strict_types=1);

namespace Techzapp\Crm\Services\Mail;

/**
 * Outcome of one send attempt.
 *
 * "accepted" means the provider took responsibility for the message — never
 * that it reached an inbox. Delivery is only known from a later delivery event.
 *
 * "uncertain" is the important third state: the request may or may not have
 * been processed (socket timeout, connection reset mid-flight). Those are
 * parked for reconciliation instead of being retried blindly, because a retry
 * would risk sending the same message twice.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $messageId,
        public readonly string $errorCode,
        public readonly string $errorMessage,
        public readonly bool $retryable,
        public readonly bool $uncertain,
        public readonly bool $permanentRejection,
        public readonly bool $accountLevelProblem
    ) {
    }

    public static function accepted(string $messageId): self
    {
        return new self(true, $messageId, '', '', false, false, false, false);
    }

    /** Transient failure: safe to retry with backoff. */
    public static function transientFailure(string $code, string $message): self
    {
        return new self(false, null, $code, $message, true, false, false, false);
    }

    /** Permanent rejection: never retry this recipient. */
    public static function permanentFailure(string $code, string $message): self
    {
        return new self(false, null, $code, $message, false, false, true, false);
    }

    /** Outcome unknown: do not resend, reconcile instead. */
    public static function uncertain(string $code, string $message): self
    {
        return new self(false, null, $code, $message, false, true, false, false);
    }

    /** Account-level stop (sending paused, quota exhausted, identity invalid). */
    public static function accountProblem(string $code, string $message): self
    {
        return new self(false, null, $code, $message, false, false, false, true);
    }
}
