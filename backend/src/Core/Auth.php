<?php

declare(strict_types=1);

namespace Techzapp\Crm\Core;

use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Support\EmailAddress;

/**
 * Session authentication, login throttling and CSRF protection.
 *
 * Every protected endpoint calls requireUser(); authorization is decided here,
 * server-side, from the session — never from anything the client sends.
 */
final class Auth
{
    private const SESSION_USER = 'user_id';
    private const SESSION_CSRF = 'csrf_token';
    private const SESSION_FINGERPRINT = 'fingerprint';
    private const SESSION_LAST_SEEN = 'last_seen_at';

    /** @var array<string,mixed>|null */
    private static ?array $cachedUser = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = Config::load();
        $savePath = $config->string('session.save_path');
        if ($savePath !== '') {
            if (!is_dir($savePath)) {
                @mkdir($savePath, 0700, true);
            }
            if (is_dir($savePath) && is_writable($savePath)) {
                session_save_path($savePath);
            }
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $config->int('session.lifetime', 28800));
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '5');

        session_name($config->string('session.name', 'techzapp_crm_session'));
        session_set_cookie_params([
            'lifetime' => 0, // browser-session cookie; server-side lifetime governs
            'path'     => $config->string('session.cookie_path', '/'),
            'domain'   => $config->string('session.cookie_domain', ''),
            'secure'   => $config->bool('session.secure_cookie', true),
            'httponly' => true,
            'samesite' => $config->string('session.same_site', 'Lax'),
        ]);

        session_start();
    }

    /** Logs a user in: regenerates the session id and binds it to the client. */
    public static function login(array $user): void
    {
        self::startSession();
        session_regenerate_id(true);

        $_SESSION[self::SESSION_USER] = (int) $user['id'];
        $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_FINGERPRINT] = self::fingerprint();
        $_SESSION[self::SESSION_LAST_SEEN] = time();
        self::$cachedUser = null;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
        self::$cachedUser = null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        self::startSession();
        $userId = $_SESSION[self::SESSION_USER] ?? null;
        if (!is_int($userId) && !is_numeric($userId)) {
            return null;
        }

        // A session is only valid for the client it was issued to.
        if (($_SESSION[self::SESSION_FINGERPRINT] ?? '') !== self::fingerprint()) {
            self::logout();
            return null;
        }

        $config = Config::load();
        $idleTimeout = $config->int('session.idle_timeout', 7200);
        $lastSeen = (int) ($_SESSION[self::SESSION_LAST_SEEN] ?? 0);
        if ($idleTimeout > 0 && $lastSeen > 0 && (time() - $lastSeen) > $idleTimeout) {
            self::logout();
            return null;
        }
        $_SESSION[self::SESSION_LAST_SEEN] = time();

        $user = Database::first(
            'SELECT id, email, name, role, timezone, is_active, last_login_at, created_at
               FROM users WHERE id = :id',
            ['id' => (int) $userId]
        );

        if ($user === null || (int) $user['is_active'] !== 1) {
            self::logout();
            return null;
        }

        self::$cachedUser = $user;
        return $user;
    }

    /** @return array<string,mixed> */
    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            throw ApiException::unauthorized();
        }
        return $user;
    }

    /** @param list<string> $roles */
    public static function requireRole(array $roles): array
    {
        $user = self::requireUser();
        if (!in_array((string) $user['role'], $roles, true)) {
            throw ApiException::forbidden();
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        return self::requireRole(['admin']);
    }

    /** Viewers may read; operators and admins may write. */
    public static function requireWriteAccess(): array
    {
        return self::requireRole(['admin', 'operator']);
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (!isset($_SESSION[self::SESSION_CSRF]) || !is_string($_SESSION[self::SESSION_CSRF])) {
            $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_CSRF];
    }

    /**
     * CSRF check for state-changing requests.
     *
     * Two independent controls: the SameSite=Lax cookie (browser-enforced) and
     * a per-session token the client must echo in a header a cross-origin page
     * cannot set without CORS approval.
     */
    public static function verifyCsrf(Request $request): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        if (($_SESSION[self::SESSION_USER] ?? null) === null) {
            return; // unauthenticated endpoints (login) are throttled instead
        }

        $expected = $_SESSION[self::SESSION_CSRF] ?? '';

        // The header is the normal path. The _csrf field exists for the file
        // download endpoints, which are submitted as real forms so the browser
        // can handle the save dialog — a form cannot set a custom header.
        $provided = $request->header('X-CSRF-Token') ?? '';
        if ($provided === '') {
            $fromBody = $request->input('_csrf');
            $provided = is_string($fromBody) ? $fromBody : '';
        }

        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
            throw new ApiException('CSRF token missing or invalid. Reload the page and try again.', 419, 'csrf_mismatch');
        }
    }

    // ------------------------------------------------------------- throttling

    /** Throws 429 when the identifier or IP has exceeded the login limit. */
    public static function assertLoginAllowed(string $email, string $ip): void
    {
        $config = Config::load();
        $window = $config->int('security.login_window_seconds', 900);
        $since = gmdate('Y-m-d H:i:s', time() - $window);
        $identifier = EmailAddress::normalize($email);

        $byIdentifier = (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE identifier = :id AND successful = 0 AND attempted_at >= :since',
            ['id' => $identifier, 'since' => $since]
        );
        if ($byIdentifier >= $config->int('security.login_max_attempts', 5)) {
            throw ApiException::tooManyRequests(
                'Too many failed sign-in attempts. Try again later.',
                $config->int('security.login_lockout_seconds', 900)
            );
        }

        $packed = @inet_pton($ip);
        if ($packed !== false) {
            $byIp = (int) Database::scalar(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE ip_address = :ip AND successful = 0 AND attempted_at >= :since',
                ['ip' => $packed, 'since' => $since]
            );
            if ($byIp >= $config->int('security.login_ip_max_attempts', 20)) {
                throw ApiException::tooManyRequests(
                    'Too many failed sign-in attempts from this network.',
                    $config->int('security.login_lockout_seconds', 900)
                );
            }
        }
    }

    public static function recordLoginAttempt(string $email, string $ip, bool $successful): void
    {
        $packed = @inet_pton($ip);
        Database::execute(
            'INSERT INTO login_attempts (identifier, ip_address, successful) VALUES (:id, :ip, :ok)',
            [
                'id' => EmailAddress::normalize($email),
                'ip' => $packed === false ? null : $packed,
                'ok' => $successful ? 1 : 0,
            ]
        );

        if ($successful) {
            // A successful sign-in clears the identifier's failure budget.
            Database::execute(
                'DELETE FROM login_attempts WHERE identifier = :id AND successful = 0',
                ['id' => EmailAddress::normalize($email)]
            );
        }
    }

    /** Password hashing: Argon2id when the build supports it, bcrypt otherwise. */
    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function needsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
        }
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** @return array<string,string> field => message */
    public static function passwordProblems(string $password, string $email = ''): array
    {
        $errors = [];
        $minimum = Config::load()->int('security.password_min_length', 12);

        if (strlen($password) < $minimum) {
            $errors['password'] = "Password must be at least {$minimum} characters.";
        } elseif (strlen($password) > 200) {
            $errors['password'] = 'Password must not exceed 200 characters.';
        } elseif (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/[0-9]/', $password) !== 1) {
            $errors['password'] = 'Password must contain both letters and numbers.';
        } elseif ($email !== '' && stripos($password, explode('@', $email)[0]) !== false) {
            $errors['password'] = 'Password must not contain your email name.';
        }

        return $errors;
    }

    private static function fingerprint(): string
    {
        // Deliberately excludes the IP: mobile networks rotate addresses and
        // would log users out constantly. The user agent is stable per device.
        return hash_hmac(
            'sha256',
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            Config::load()->secretKey()
        );
    }

    /** Test seam. */
    public static function clearCache(): void
    {
        self::$cachedUser = null;
    }
}
