<?php

declare(strict_types=1);

namespace Techzapp\Crm\Http;

use RuntimeException;
use Throwable;

/** An error that is safe to render to the API client. */
class ApiException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly string $errorCode = 'bad_request',
        private readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** @param array<string,string> $errors field => message */
    public static function validation(array $errors, string $message = 'The submitted data is invalid.'): self
    {
        return new self($message, 422, 'validation_failed', ['fields' => $errors]);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self($message, 401, 'unauthenticated');
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self($message, 403, 'forbidden');
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self($message, 404, 'not_found');
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self($message, 409, 'conflict', $details);
    }

    public static function tooManyRequests(string $message, int $retryAfter = 60): self
    {
        return new self($message, 429, 'rate_limited', ['retry_after' => $retryAfter]);
    }

    public static function unprocessable(string $message, array $details = []): self
    {
        return new self($message, 422, 'unprocessable', $details);
    }
}
