<?php

declare(strict_types=1);

namespace Techzapp\Crm\Http;

/**
 * Consistent JSON envelope.
 *
 *   success: {"data": …, "meta": {…}}
 *   failure: {"error": {"code": "…", "message": "…", "details": {…}}}
 */
final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        private readonly int $status,
        private readonly mixed $payload,
        private array $headers = []
    ) {
    }

    public static function json(mixed $data, int $status = 200, array $meta = []): self
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        return new self($status, $payload);
    }

    public static function created(mixed $data): self
    {
        return self::json($data, 201);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    /** @param array<string,mixed> $details */
    public static function error(string $message, int $status = 400, string $code = 'bad_request', array $details = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return new self($status, ['error' => $error]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
            header('Cache-Control: no-store, max-age=0');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->status === 204 || $this->payload === null) {
            return;
        }

        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
