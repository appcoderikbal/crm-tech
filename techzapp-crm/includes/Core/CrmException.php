<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use RuntimeException;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A failure that is safe to show the operator.
 *
 * The service layer throws these for "you cannot do that" situations —
 * a duplicate address, a campaign in the wrong state, a list still in use.
 * Admin screens turn them into a notice; REST routes turn them into a
 * WP_Error. Anything else that escapes is logged and shown as a generic
 * message, because unexpected failures must not leak internals.
 */
class CrmException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'error',
        private readonly array $details = [],
        private readonly int $statusCode = 400,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
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

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** Field-level messages, when the failure was a validation failure. */
    public function fieldErrors(): array
    {
        $fields = $this->details['fields'] ?? [];
        return is_array($fields) ? $fields : [];
    }

    /** @param array<string,string> $errors field => message */
    public static function validation(array $errors, string $message = ''): self
    {
        if ($message === '') {
            $message = count($errors) === 1
                ? (string) reset($errors)
                : __('Please correct the highlighted fields.', 'techzapp-crm');
        }

        return new self($message, 'validation_failed', ['fields' => $errors], 422);
    }

    public static function notFound(string $message = ''): self
    {
        return new self(
            $message !== '' ? $message : __('That item no longer exists.', 'techzapp-crm'),
            'not_found',
            [],
            404
        );
    }

    /** @param array<string,mixed> $details */
    public static function conflict(string $message, array $details = []): self
    {
        return new self($message, 'conflict', $details, 409);
    }

    /** @param array<string,mixed> $details */
    public static function unprocessable(string $message, array $details = []): self
    {
        return new self($message, 'unprocessable', $details, 422);
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(
            $message !== '' ? $message : __('You do not have permission to do that.', 'techzapp-crm'),
            'forbidden',
            [],
            403
        );
    }

    public function toWpError(): \WP_Error
    {
        return new \WP_Error(
            $this->errorCode,
            $this->getMessage(),
            ['status' => $this->statusCode] + $this->details
        );
    }
}
