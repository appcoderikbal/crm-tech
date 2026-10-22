<?php

declare(strict_types=1);

namespace TechzappCrm\Core;

use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cooperative, expiring locks.
 *
 * WordPress has no primitive for this — transients are cached and can be
 * served stale, which is exactly wrong for a sending mutex — so the lock lives
 * in a table and acquisition is a single atomic statement. Two cron runs
 * starting in the same second cannot both own it, and a worker killed by the
 * host releases its lock when the lease expires.
 */
final class Lock
{
    private bool $held = false;

    private function __construct(
        private readonly string $key,
        private readonly string $token,
        private readonly int $ttlSeconds
    ) {
    }

    /** Returns a held lock, or null when another worker owns it. */
    public static function acquire(string $key, int $ttlSeconds = 300, string $ownerInfo = ''): ?self
    {
        $token = bin2hex(random_bytes(16));
        $now = Database::nowUtc();
        $expires = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        if ($ownerInfo === '') {
            $ownerInfo = (defined('WP_CLI') && WP_CLI) ? 'wp-cli' : 'pid ' . getmypid();
        }

        try {
            // Insert when free; steal only when the previous lease expired.
            $affected = Database::execute(
                'INSERT INTO {processing_locks} (lock_key, owner_token, owner_info, acquired_at, heartbeat_at, expires_at)
                 VALUES (:k, :token, :info, :now, :now2, :expires)
                 ON DUPLICATE KEY UPDATE
                   owner_token  = IF(expires_at < VALUES(acquired_at), VALUES(owner_token), owner_token),
                   owner_info   = IF(owner_token = VALUES(owner_token), VALUES(owner_info), owner_info),
                   acquired_at  = IF(owner_token = VALUES(owner_token), VALUES(acquired_at), acquired_at),
                   heartbeat_at = IF(owner_token = VALUES(owner_token), VALUES(heartbeat_at), heartbeat_at),
                   expires_at   = IF(owner_token = VALUES(owner_token), VALUES(expires_at), expires_at)',
                [
                    'k'       => $key,
                    'token'   => $token,
                    'info'    => substr($ownerInfo, 0, 190),
                    'now'     => $now,
                    'now2'    => $now,
                    'expires' => $expires,
                ]
            );

            if ($affected === 0) {
                return null;
            }
        } catch (Throwable $e) {
            Logger::exception('lock.acquire_failed', $e, ['key' => $key]);
            return null;
        }

        // Confirm ownership: a concurrent winner may have written first.
        $owner = Database::scalar('SELECT owner_token FROM {processing_locks} WHERE lock_key = :k', ['k' => $key]);
        if (!is_string($owner) || !hash_equals($token, $owner)) {
            return null;
        }

        $lock = new self($key, $token, $ttlSeconds);
        $lock->held = true;
        return $lock;
    }

    /** Extends the lease. Returns false if ownership was lost. */
    public function heartbeat(): bool
    {
        if (!$this->held) {
            return false;
        }

        $affected = Database::execute(
            'UPDATE {processing_locks}
                SET heartbeat_at = :now, expires_at = :expires
              WHERE lock_key = :k AND owner_token = :token',
            [
                'now'     => Database::nowUtc(),
                'expires' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
                'k'       => $this->key,
                'token'   => $this->token,
            ]
        );

        if ($affected === 0) {
            // MySQL reports zero affected rows when the new values match the
            // stored ones, which happens whenever two heartbeats land in the
            // same second. Confirm ownership before concluding the lease was lost.
            $owner = Database::scalar(
                'SELECT owner_token FROM {processing_locks} WHERE lock_key = :k',
                ['k' => $this->key]
            );
            $this->held = is_string($owner) && hash_equals($this->token, $owner);
        }

        return $this->held;
    }

    public function isHeld(): bool
    {
        return $this->held;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        Database::execute(
            'DELETE FROM {processing_locks} WHERE lock_key = :k AND owner_token = :token',
            ['k' => $this->key, 'token' => $this->token]
        );
        $this->held = false;
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (Throwable) {
            // Lease expiry is the backstop.
        }
    }
}
