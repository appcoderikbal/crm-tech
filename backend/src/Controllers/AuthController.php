<?php

declare(strict_types=1);

namespace Techzapp\Crm\Controllers;

use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;
use Techzapp\Crm\Http\Validator;
use Techzapp\Crm\Services\AlertService;
use Techzapp\Crm\Support\EmailAddress;

final class AuthController
{
    public function login(Request $request): Response
    {
        $validator = Validator::make($request->body());
        $email = $validator->string('email', true, 255);
        $password = $validator->raw('password');
        $validator->validate();

        if (!is_string($password) || $password === '') {
            throw ApiException::validation(['password' => 'This field is required.']);
        }

        $ip = $request->ip();
        Auth::assertLoginAllowed((string) $email, $ip);

        $user = Database::first(
            'SELECT id, email, name, role, timezone, is_active, password_hash
               FROM users WHERE normalized_email = :email',
            ['email' => EmailAddress::normalize((string) $email)]
        );

        // Always run a hash verification so a missing account and a wrong
        // password take the same amount of time.
        $hash = (string) ($user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv');
        $passwordOk = password_verify($password, $hash);

        if ($user === null || !$passwordOk || (int) $user['is_active'] !== 1) {
            Auth::recordLoginAttempt((string) $email, $ip, false);
            Audit::log('auth.login_failed', 'user', (string) ($user['id'] ?? ''), ['email' => $email]);

            // Deliberately identical message for every failure mode.
            throw new ApiException('Email address or password is incorrect.', 401, 'invalid_credentials');
        }

        if (Auth::needsRehash($hash)) {
            Database::execute(
                'UPDATE users SET password_hash = :hash WHERE id = :id',
                ['hash' => Auth::hashPassword($password), 'id' => (int) $user['id']]
            );
        }

        Auth::recordLoginAttempt((string) $email, $ip, true);
        Auth::login($user);

        Database::execute('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => (int) $user['id']]);
        Audit::log('auth.login', 'user', (int) $user['id'], [], (int) $user['id'], (string) $user['email']);

        unset($user['password_hash']);
        $user['id'] = (int) $user['id'];
        $user['is_active'] = (bool) $user['is_active'];

        return Response::json([
            'user'       => $user,
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    public function logout(Request $request): Response
    {
        $user = Auth::user();
        if ($user !== null) {
            Audit::log('auth.logout', 'user', (int) $user['id'], [], (int) $user['id'], (string) $user['email']);
        }
        Auth::logout();

        return Response::json(['logged_out' => true]);
    }

    public function me(Request $request): Response
    {
        $user = Auth::requireUser();
        $user['id'] = (int) $user['id'];
        $user['is_active'] = (bool) $user['is_active'];

        return Response::json([
            'user'           => $user,
            'csrf_token'     => Auth::csrfToken(),
            'critical_alerts' => AlertService::criticalCount(),
            'open_alerts'    => AlertService::unacknowledgedCount(),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = Auth::requireUser();

        $validator = Validator::make($request->body());
        $name = $validator->string('name', false, 120);
        $timezone = $validator->has('timezone') ? $validator->timezone('timezone') : null;
        $validator->validate();

        $fields = [];
        $params = ['id' => (int) $user['id']];
        if ($name !== null) {
            $fields[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($timezone !== null) {
            $fields[] = 'timezone = :timezone';
            $params['timezone'] = $timezone;
        }

        if ($fields !== []) {
            Database::execute('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id', $params);
            Audit::log('user.profile_updated', 'user', (int) $user['id'], array_keys($params), (int) $user['id']);
        }

        Auth::clearCache();
        return Response::json(['user' => Auth::requireUser()]);
    }

    public function changePassword(Request $request): Response
    {
        $user = Auth::requireUser();

        $current = $request->input('current_password');
        $new = $request->input('new_password');

        if (!is_string($current) || !is_string($new)) {
            throw ApiException::validation([
                'current_password' => 'This field is required.',
                'new_password'     => 'This field is required.',
            ]);
        }

        $hash = (string) Database::scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => (int) $user['id']]);
        if (!password_verify($current, $hash)) {
            Audit::log('user.password_change_failed', 'user', (int) $user['id'], [], (int) $user['id']);
            throw ApiException::validation(['current_password' => 'Your current password is incorrect.']);
        }

        $problems = Auth::passwordProblems($new, (string) $user['email']);
        if ($problems !== []) {
            throw ApiException::validation($problems);
        }
        if (password_verify($new, $hash)) {
            throw ApiException::validation(['new_password' => 'Choose a password you have not used here before.']);
        }

        Database::execute(
            'UPDATE users SET password_hash = :hash, password_changed_at = UTC_TIMESTAMP() WHERE id = :id',
            ['hash' => Auth::hashPassword($new), 'id' => (int) $user['id']]
        );

        Audit::log('user.password_changed', 'user', (int) $user['id'], [], (int) $user['id']);

        // Re-issue the session so any other session for this account is not
        // silently left usable with the old credentials.
        Auth::login($user);

        return Response::json(['changed' => true, 'csrf_token' => Auth::csrfToken()]);
    }
}
