<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Api\ApiException;
use Haccp\Repository\AuthRepository;
use Haccp\Support\Clock;

final readonly class AuthService
{
    public const COOKIE = 'haccp_session';
    public const ROLES = ['administrator', 'operator', 'auditor'];

    public function __construct(
        private AuthRepository $repository,
        private AuditService $audit,
        private Clock $clock,
    ) {
    }

    public function bootstrapAdmin(string $username, string $password): void
    {
        if ($this->repository->countUsers() !== 0) {
            return;
        }
        $now = $this->clock->database($this->clock->now());
        $id = $this->repository->createUser([
            'username' => $this->normalizeUsername($username),
            'display_name' => 'Systemadministrator',
            'email' => null,
            'role' => 'administrator',
            'password_hash' => $this->hashPassword($password),
            'password_change_required' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->append('user.bootstrap', $id, 'user', (string) $id, ['role' => 'administrator']);
    }

    /** @return array{token: string, csrf_token: string, user: array<string, mixed>} */
    public function login(string $username, string $password): array
    {
        $username = $this->normalizeUsername($username);
        $nowObject = $this->clock->now();
        $now = $this->clock->database($nowObject);
        $usernameHash = hash('sha256', $username);
        $user = $this->repository->userByUsername($username);
        $locked = $this->repository->recentFailures($usernameHash, $this->clock->database($nowObject->modify('-15 minutes'))) >= 5;
        if ($user !== null && $user['locked_until'] !== null && new \DateTimeImmutable((string) $user['locked_until'], new \DateTimeZone('UTC')) > $nowObject) {
            $locked = true;
        }
        $valid = !$locked && $user !== null && (bool) $user['active'] && password_verify($password, (string) $user['password_hash']);
        $this->repository->recordAttempt($usernameHash, $valid, $now);
        if (!$valid) {
            $failureCount = $this->repository->recentFailures($usernameHash, $this->clock->database($nowObject->modify('-15 minutes')));
            $this->repository->recordFailedLogin(
                $user === null ? null : (int) $user['id'],
                $failureCount,
                $now,
                $this->clock->database($nowObject->modify('+15 minutes')),
            );
            $this->audit->append('auth.login_failed', $user === null ? null : (int) $user['id'], 'credential', $usernameHash, ['locked' => $locked || $failureCount >= 5]);
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Benutzername oder Passwort ist nicht korrekt.');
        }

        $userId = (int) $user['id'];
        $this->repository->recordSuccessfulLogin($userId, $now);
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $this->repository->createSession(
            $userId,
            hash('sha256', $token),
            $csrf,
            $now,
            $this->clock->database($nowObject->modify('+12 hours')),
            $this->clock->database($nowObject->modify('+7 days')),
        );
        $this->audit->append('auth.login', $userId, 'user', (string) $userId);

        return ['token' => $token, 'csrf_token' => $csrf, 'user' => $this->publicUser($user)];
    }

    /** @return array<string, mixed>|null */
    public function authenticate(?string $token): ?array
    {
        if ($token === null || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $nowObject = $this->clock->now();
        $now = $this->clock->database($nowObject);
        $session = $this->repository->session(hash('sha256', $token), $now);
        if ($session === null) {
            return null;
        }
        $absolute = new \DateTimeImmutable((string) $session['absolute_expires_at'], new \DateTimeZone('UTC'));
        $idle = min($absolute->getTimestamp(), $nowObject->modify('+12 hours')->getTimestamp());
        $this->repository->touchSession((int) $session['session_id'], $now, $this->clock->database((new \DateTimeImmutable('@' . $idle))->setTimezone(new \DateTimeZone('UTC'))));

        return $session;
    }

    public function logout(?string $token, ?int $userId = null): void
    {
        if ($token !== null && preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->repository->deleteSessionByToken(hash('sha256', $token));
        }
        $this->audit->append('auth.logout', $userId, 'user', $userId === null ? null : (string) $userId);
    }

    public function changePassword(array $user, string $currentPassword, string $newPassword): void
    {
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new ApiException(422, 'CURRENT_PASSWORD_INVALID', 'Das aktuelle Passwort ist nicht korrekt.');
        }
        $this->validatePassword($newPassword);
        $id = (int) $user['id'];
        $this->repository->updatePassword($id, $this->hashPassword($newPassword), false, $this->clock->database($this->clock->now()));
        $this->repository->revokeUserSessions($id);
        $this->audit->append('user.password_changed', $id, 'user', (string) $id);
    }

    public function verifyPassword(array $user, string $password): bool
    {
        return password_verify($password, (string) $user['password_hash']);
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function updateThemePreference(array $user, string $theme): array
    {
        if (!in_array($theme, ['system', 'light', 'dark'], true)) {
            throw new ApiException(422, 'INVALID_THEME_PREFERENCE', 'Das Farbschema muss system, light oder dark sein.');
        }
        $this->repository->updateThemePreference(
            (int) $user['id'],
            $theme,
            $this->clock->database($this->clock->now()),
        );

        return ['theme_preference' => $theme];
    }

    public function hashPassword(string $password): string
    {
        $this->validatePassword($password);
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if (!is_string($hash)) {
            throw new \RuntimeException('Password could not be hashed.');
        }

        return $hash;
    }

    public function validatePassword(string $password): void
    {
        $length = mb_strlen($password);
        if ($length < 12 || $length > 128) {
            throw new ApiException(422, 'PASSWORD_POLICY_VIOLATION', 'Das Passwort muss 12 bis 128 Zeichen lang sein.');
        }
    }

    /** @return array<string, mixed> */
    public function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'display_name' => (string) $user['display_name'],
            'email' => $user['email'],
            'role' => (string) $user['role'],
            'theme_preference' => in_array((string) ($user['theme_preference'] ?? 'system'), ['system', 'light', 'dark'], true)
                ? (string) ($user['theme_preference'] ?? 'system')
                : 'system',
            'password_change_required' => (bool) $user['password_change_required'],
            'active' => (bool) $user['active'],
        ];
    }

    private function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }
}
