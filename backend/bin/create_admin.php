<?php

declare(strict_types=1);

/**
 * Creates the first administrator.
 *
 * CLI only — there is deliberately no public registration endpoint, and no web
 * route that can create an administrator. Run it once over SSH after deploying:
 *
 *   php backend/bin/create_admin.php --email=you@example.com
 *   php backend/bin/create_admin.php --email=you@example.com --name="Jane Doe"
 *   php backend/bin/create_admin.php --email=you@example.com --reset-password
 *
 * The password is read interactively (never echoed, never in shell history).
 * Pass --generate to have a strong one generated and printed once.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Techzapp\Crm\Bootstrap;
use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Support\EmailAddress;
use Techzapp\Crm\Support\Tokens;

Bootstrap::init('cli');

/** @return array<string,string> */
$parseArguments = static function (array $argv): array {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches) === 1) {
            $options[$matches[1]] = $matches[2] ?? '1';
        }
    }
    return $options;
};

$readSecret = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);

    // Hide the input where the platform allows it.
    if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
        $value = (string) fgets(STDIN);
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    } else {
        fwrite(STDOUT, "\n(input will be visible on this platform) ");
        $value = (string) fgets(STDIN);
    }

    return trim($value);
};

$options = $parseArguments($argv);
$email = trim((string) ($options['email'] ?? ''));
$name = trim((string) ($options['name'] ?? ''));
$role = in_array($options['role'] ?? 'admin', ['admin', 'operator', 'viewer'], true)
    ? (string) ($options['role'] ?? 'admin')
    : 'admin';
$resetPassword = isset($options['reset-password']);

if ($email === '') {
    fwrite(STDOUT, 'Email address: ');
    $email = trim((string) fgets(STDIN));
}

if (!EmailAddress::isValid($email)) {
    fwrite(STDERR, "Error: '{$email}' is not a valid email address.\n");
    exit(1);
}

$normalized = EmailAddress::normalize($email);
$existing = Database::first('SELECT id, role FROM users WHERE normalized_email = :email', ['email' => $normalized]);

if ($existing !== null && !$resetPassword) {
    fwrite(STDERR, "Error: a user with this email already exists (id {$existing['id']}).\n");
    fwrite(STDERR, "Use --reset-password to set a new password for that account.\n");
    exit(1);
}

$adminCount = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
if ($existing === null && $adminCount > 0 && !isset($options['force'])) {
    fwrite(STDOUT, "There is already an active administrator ({$adminCount} total).\n");
    fwrite(STDOUT, "Create another user anyway? [y/N]: ");
    if (strtolower(trim((string) fgets(STDIN))) !== 'y') {
        fwrite(STDOUT, "Cancelled.\n");
        exit(0);
    }
}

// --- password ---------------------------------------------------------------
if (isset($options['generate'])) {
    // 24 URL-safe characters, well above the configured minimum.
    $password = substr(Tokens::base64UrlEncode(random_bytes(24)), 0, 24) . '7a';
    fwrite(STDOUT, "\nGenerated password (store it now, it is not shown again):\n  {$password}\n\n");
} else {
    $password = $readSecret('Password: ');
    $confirm = $readSecret('Confirm password: ');

    if ($password !== $confirm) {
        fwrite(STDERR, "Error: the passwords do not match.\n");
        exit(1);
    }
}

$problems = Auth::passwordProblems($password, $email);
if ($problems !== []) {
    fwrite(STDERR, 'Error: ' . implode(' ', $problems) . "\n");
    exit(1);
}

$hash = Auth::hashPassword($password);

if ($existing !== null) {
    Database::execute(
        'UPDATE users SET password_hash = :hash, password_changed_at = UTC_TIMESTAMP(), is_active = 1 WHERE id = :id',
        ['hash' => $hash, 'id' => (int) $existing['id']]
    );
    Audit::log('user.password_reset_cli', 'user', (int) $existing['id'], ['via' => 'create_admin.php']);
    fwrite(STDOUT, "Password updated for {$email} (id {$existing['id']}).\n");
    exit(0);
}

Database::execute(
    'INSERT INTO users (email, normalized_email, password_hash, name, role, is_active, password_changed_at)
     VALUES (:email, :normalized, :hash, :name, :role, 1, UTC_TIMESTAMP())',
    [
        'email'      => $email,
        'normalized' => $normalized,
        'hash'       => $hash,
        'name'       => $name !== '' ? $name : explode('@', $email)[0],
        'role'       => $role,
    ]
);

$userId = Database::lastInsertId();
Audit::log('user.created_cli', 'user', $userId, ['email' => $email, 'role' => $role]);

fwrite(STDOUT, "Created {$role} account for {$email} (id {$userId}).\n");
fwrite(STDOUT, "Sign in at your application URL and change the password from Settings → Profile.\n");
exit(0);
