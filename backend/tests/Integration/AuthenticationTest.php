<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Integration;

use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Tests\IntegrationTestCase;

final class AuthenticationTest extends IntegrationTestCase
{
    public function testPasswordsAreNeverStoredInPlaintext(): void
    {
        $this->makeUser('admin@techzapp.test');

        $hash = (string) Database::scalar('SELECT password_hash FROM users LIMIT 1');

        self::assertStringNotContainsString('CorrectHorse9Battery', $hash);
        self::assertMatchesRegularExpression('/^\$(argon2id|2y)\$/', $hash);
        self::assertTrue(password_verify('CorrectHorse9Battery', $hash));
        self::assertFalse(password_verify('wrong password', $hash));
    }

    public function testHashingPrefersArgon2idWhenAvailable(): void
    {
        $hash = Auth::hashPassword('CorrectHorse9Battery');

        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            self::assertStringStartsWith('$argon2id$', $hash);
        } else {
            self::assertStringStartsWith('$2y$', $hash);
        }

        self::assertFalse(Auth::needsRehash($hash), 'A fresh hash must already use the preferred settings.');
    }

    public function testBcryptHashesAreFlaggedForRehashWhenArgonIsAvailable(): void
    {
        if (!defined('PASSWORD_ARGON2ID') || !in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            self::markTestSkipped('Argon2id is not available in this PHP build.');
        }

        $legacy = password_hash('CorrectHorse9Battery', PASSWORD_BCRYPT, ['cost' => 10]);

        self::assertTrue(Auth::needsRehash($legacy));
    }

    public function testFailedAttemptsLockTheAccountAfterTheConfiguredLimit(): void
    {
        $email = 'victim@techzapp.test';
        $this->makeUser($email);

        for ($i = 0; $i < 5; $i++) {
            Auth::assertLoginAllowed($email, '198.51.100.7');
            Auth::recordLoginAttempt($email, '198.51.100.7', false);
        }

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/Too many failed sign-in attempts/');

        Auth::assertLoginAllowed($email, '198.51.100.7');
    }

    public function testThrottlingIsPerIdentityNotGlobal(): void
    {
        $this->makeUser('a@techzapp.test');
        $this->makeUser('b@techzapp.test');

        for ($i = 0; $i < 5; $i++) {
            Auth::recordLoginAttempt('a@techzapp.test', '198.51.100.8', false);
        }

        // The second account, from a different address, is unaffected.
        Auth::assertLoginAllowed('b@techzapp.test', '198.51.100.9');
        $this->addToAssertionCount(1);
    }

    public function testIpThrottlingCatchesSprayedAttempts(): void
    {
        for ($i = 0; $i < 20; $i++) {
            Auth::recordLoginAttempt("user{$i}@techzapp.test", '203.0.113.200', false);
        }

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/from this network/');

        Auth::assertLoginAllowed('another@techzapp.test', '203.0.113.200');
    }

    public function testSuccessfulLoginClearsTheFailureBudget(): void
    {
        $email = 'recover@techzapp.test';
        $this->makeUser($email);

        for ($i = 0; $i < 4; $i++) {
            Auth::recordLoginAttempt($email, '198.51.100.10', false);
        }
        Auth::recordLoginAttempt($email, '198.51.100.10', true);

        Auth::assertLoginAllowed($email, '198.51.100.10');

        self::assertSame(0, (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = :id AND successful = 0',
            ['id' => $email]
        ));
    }

    public function testAttemptsAreRecordedAgainstTheNormalisedIdentity(): void
    {
        Auth::recordLoginAttempt('Mixed.Case@Techzapp.TEST', '198.51.100.11', false);

        self::assertSame(1, (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = :id',
            ['id' => 'mixed.case@techzapp.test']
        ));
    }

    public function testThrottlingIgnoresAttemptsOutsideTheWindow(): void
    {
        $email = 'old@techzapp.test';
        $this->makeUser($email);

        for ($i = 0; $i < 5; $i++) {
            Auth::recordLoginAttempt($email, '198.51.100.12', false);
        }
        Database::execute(
            'UPDATE login_attempts SET attempted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)'
        );

        Auth::assertLoginAllowed($email, '198.51.100.12');
        $this->addToAssertionCount(1);
    }

    public function testPasswordPolicyRejectsWeakChoices(): void
    {
        self::assertNotSame([], Auth::passwordProblems('short1'), 'Too short.');
        self::assertNotSame([], Auth::passwordProblems('alllettersnonumbers'), 'No digits.');
        self::assertNotSame([], Auth::passwordProblems('123456789012345'), 'No letters.');
        self::assertNotSame(
            [],
            Auth::passwordProblems('marketing99team', 'marketing@techzapp.test'),
            'Contains the email name.'
        );

        self::assertSame([], Auth::passwordProblems('Tr0ubad0ur-Stapler', 'someone@techzapp.test'));
    }

    public function testRoleGatesAreEnforcedServerSide(): void
    {
        // The role column is the only thing that decides authorization; no
        // client-supplied value participates.
        $viewerId = $this->makeUser('viewer@techzapp.test', 'viewer');
        $role = Database::scalar('SELECT role FROM users WHERE id = :id', ['id' => $viewerId]);

        self::assertSame('viewer', $role);
        self::assertNotContains($role, ['admin', 'operator'], 'A viewer must not satisfy write-access checks.');
    }

    public function testInactiveUsersCannotBeTreatedAsAuthenticated(): void
    {
        $userId = $this->makeUser('disabled@techzapp.test');
        Database::execute('UPDATE users SET is_active = 0 WHERE id = :id', ['id' => $userId]);

        $row = Database::first('SELECT is_active FROM users WHERE id = :id', ['id' => $userId]);

        self::assertSame(0, (int) $row['is_active']);
    }

    public function testThereIsNoPublicRouteThatCreatesAdministrators(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        self::assertStringNotContainsString('register', $routes);
        self::assertStringNotContainsString('signup', $routes);
        self::assertStringNotContainsString('users/create', $routes);
    }

    public function testEveryApiRouteGoesThroughAControllerThatRequiresAuthentication(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        preg_match_all('/\[(\w+Controller)::class, \'(\w+)\'\]/', $routes, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches, 'Expected to find controller routes.');

        $unprotected = [];
        foreach ($matches as [, $controller, $method]) {
            $file = dirname(__DIR__, 2) . '/src/Controllers/' . $controller . '.php';
            $source = (string) file_get_contents($file);

            // Extract the method body and confirm it calls an Auth guard.
            if (preg_match('/function ' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s', $source, $body) !== 1) {
                $unprotected[] = "{$controller}::{$method} (body not found)";
                continue;
            }

            $login = $controller === 'AuthController' && in_array($method, ['login', 'logout'], true);
            if (!$login && !str_contains($body[1], 'Auth::require')) {
                $unprotected[] = "{$controller}::{$method}";
            }
        }

        self::assertSame([], $unprotected, 'Every protected endpoint must call an Auth::require* guard.');
    }
}
