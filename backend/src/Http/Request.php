<?php

declare(strict_types=1);

namespace Techzapp\Crm\Http;

use Techzapp\Crm\Core\Config;

/** Normalised view of the incoming HTTP request. */
final class Request
{
    /** @param array<string,mixed> $body @param array<string,mixed> $query */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $server
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        // Support both /api/v1/... rewrites and direct index.php access.
        $path = '/' . trim($path, '/');
        if (($position = strpos($path, '/api/')) !== false) {
            $path = substr($path, $position);
        }

        return new self($method, $path, $_GET, self::parseBody(), $_SERVER);
    }

    /** @param array<string,mixed> $query @param array<string,mixed> $body */
    public static function fake(string $method, string $path, array $query = [], array $body = [], array $server = []): self
    {
        return new self(strtoupper($method), '/' . trim($path, '/'), $query, $body, $server);
    }

    /** @return array<string,mixed> */
    private static function parseBody(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'application/json')) {
            $maxBytes = Config::load()->int('security.max_request_bytes', 2 * 1024 * 1024);
            $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
            if ($raw === false || $raw === '') {
                return [];
            }
            if (strlen($raw) > $maxBytes) {
                throw new ApiException('Request body is too large.', 413, 'payload_too_large');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new ApiException('Request body must be a JSON object.', 400, 'invalid_json');
            }
            return $decoded;
        }

        // multipart/form-data (file uploads) and urlencoded forms
        return $_POST;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->body + $this->query;
    }

    /** @return array<string,mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? ($name === 'Content-Type' ? ($this->server['CONTENT_TYPE'] ?? null) : null);
        return is_string($value) ? $value : null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization') ?? '';
        return preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1 ? trim($m[1]) : null;
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '');

        // Hostinger terminates TLS/CDN in front of PHP; trust the first hop only
        // when the platform set it, and fall back to REMOTE_ADDR otherwise.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
            $value = $this->server[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $candidate = trim(explode(',', $value)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $remote;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function origin(): ?string
    {
        $origin = $this->server['HTTP_ORIGIN'] ?? null;
        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    public function isHttps(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
