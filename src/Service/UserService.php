<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Api\ApiException;
use Haccp\Repository\AuthRepository;
use Haccp\Support\Clock;
use PDOException;

final readonly class UserService
{
    public function __construct(
        private AuthRepository $users,
        private AuthService $auth,
        private AuditService $audit,
        private Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return array_map(fn (array $user): array => $this->auth->publicUser($user) + [
            'last_login_at' => $user['last_login_at'],
            'locked_until' => $user['locked_until'],
            'created_at' => $user['created_at'],
        ], $this->users->users());
    }

    /** @return array{user: array<string, mixed>, temporary_password: string} */
    public function create(object $payload, int $actorId): array
    {
        $username = mb_strtolower(trim(is_string($payload->username ?? null) ? $payload->username : ''));
        $displayName = trim(is_string($payload->display_name ?? null) ? $payload->display_name : '');
        $email = $this->email($payload->email ?? null);
        $role = is_string($payload->role ?? null) ? $payload->role : '';
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', $username)) {
            throw new ApiException(422, 'USER_VALIDATION_FAILED', 'Der Benutzername ist ungültig.', ['field' => 'username']);
        }
        if ($displayName === '' || mb_strlen($displayName) > 160 || !in_array($role, AuthService::ROLES, true)) {
            throw new ApiException(422, 'USER_VALIDATION_FAILED', 'Anzeigename oder Rolle ist ungültig.');
        }
        $temporaryPassword = $this->temporaryPassword();
        $now = $this->clock->database($this->clock->now());
        try {
            $id = $this->users->createUser([
                'username' => $username,
                'display_name' => $displayName,
                'email' => $email,
                'role' => $role,
                'password_hash' => $this->auth->hashPassword($temporaryPassword),
                'password_change_required' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new ApiException(409, 'USERNAME_EXISTS', 'Dieser Benutzername ist bereits vergeben.');
            }
            throw $exception;
        }
        $this->audit->append('user.created', $actorId, 'user', (string) $id, ['role' => $role]);

        return ['user' => $this->auth->publicUser($this->users->userById($id) ?? []), 'temporary_password' => $temporaryPassword];
    }

    /** @return array<string, mixed> */
    public function update(int $id, object $payload, int $actorId): array
    {
        $existing = $this->users->userById($id);
        if ($existing === null) {
            throw new ApiException(404, 'USER_NOT_FOUND', 'Der Benutzer wurde nicht gefunden.');
        }
        $displayName = trim(is_string($payload->display_name ?? null) ? $payload->display_name : (string) $existing['display_name']);
        $email = property_exists($payload, 'email') ? $this->email($payload->email) : $existing['email'];
        $role = is_string($payload->role ?? null) ? $payload->role : (string) $existing['role'];
        $active = is_bool($payload->active ?? null) ? $payload->active : (bool) $existing['active'];
        if ($displayName === '' || mb_strlen($displayName) > 160 || !in_array($role, AuthService::ROLES, true)) {
            throw new ApiException(422, 'USER_VALIDATION_FAILED', 'Anzeigename oder Rolle ist ungültig.');
        }
        if ((string) $existing['role'] === 'administrator' && (bool) $existing['active']
            && (!$active || $role !== 'administrator') && $this->users->activeAdministratorCount() <= 1) {
            throw new ApiException(409, 'LAST_ADMIN_REQUIRED', 'Der letzte aktive Administrator kann nicht deaktiviert oder herabgestuft werden.');
        }
        $this->users->updateUser($id, $displayName, $email, $role, $active, $this->clock->database($this->clock->now()));
        if (!$active || $role !== (string) $existing['role']) {
            $this->users->revokeUserSessions($id);
        }
        $this->audit->append('user.updated', $actorId, 'user', (string) $id, ['role' => $role, 'active' => $active]);

        return $this->auth->publicUser($this->users->userById($id) ?? []);
    }

    public function resetPassword(int $id, int $actorId): string
    {
        if ($this->users->userById($id) === null) {
            throw new ApiException(404, 'USER_NOT_FOUND', 'Der Benutzer wurde nicht gefunden.');
        }
        $temporary = $this->temporaryPassword();
        $this->users->updatePassword($id, $this->auth->hashPassword($temporary), true, $this->clock->database($this->clock->now()));
        $this->users->revokeUserSessions($id);
        $this->audit->append('user.password_reset', $actorId, 'user', (string) $id);

        return $temporary;
    }

    private function email(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException(422, 'USER_VALIDATION_FAILED', 'Die E-Mail-Adresse ist ungültig.', ['field' => 'email']);
        }

        return mb_strtolower(trim($value));
    }

    private function temporaryPassword(): string
    {
        return 'Hc!' . rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'AZ'), '=');
    }
}
