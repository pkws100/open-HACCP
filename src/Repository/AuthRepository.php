<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class AuthRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function countUsers(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function userByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE username = :username');
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function userById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, display_name, email, role, password_hash, password_change_required,
                    active, locked_until, last_login_at, created_at, updated_at
             FROM users WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function users(): array
    {
        return $this->pdo->query(
            'SELECT id, username, display_name, email, role, password_change_required, active,
                    locked_until, last_login_at, created_at, updated_at
             FROM users ORDER BY active DESC, display_name, username',
        )->fetchAll();
    }

    public function activeAdministratorCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'administrator'")->fetchColumn();
    }

    public function createUser(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users
             (username, display_name, email, role, password_hash, password_change_required, active,
              failed_login_count, created_at, updated_at)
             VALUES (:username, :display_name, :email, :role, :password_hash, :password_change_required,
                     1, 0, :created_at, :updated_at)',
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateUser(int $id, string $displayName, ?string $email, string $role, bool $active, string $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET display_name = :display_name, email = :email, role = :role, active = :active,
                    updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'display_name' => $displayName,
            'email' => $email,
            'role' => $role,
            'active' => $active ? 1 : 0,
            'updated_at' => $now,
            'id' => $id,
        ]);

        return $statement->rowCount() > 0 || $this->userById($id) !== null;
    }

    public function updatePassword(int $id, string $passwordHash, bool $changeRequired, string $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = :password_hash, password_change_required = :required,
                    failed_login_count = 0, locked_until = NULL, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'password_hash' => $passwordHash,
            'required' => $changeRequired ? 1 : 0,
            'updated_at' => $now,
            'id' => $id,
        ]);
    }

    public function recoverAdministrator(int $id, string $passwordHash, string $now): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE users SET role = 'administrator', active = 1, password_hash = :password_hash,
                    password_change_required = 1, failed_login_count = 0, locked_until = NULL,
                    updated_at = :updated_at WHERE id = :id",
        );
        $statement->execute(['password_hash' => $passwordHash, 'updated_at' => $now, 'id' => $id]);
        $this->revokeUserSessions($id);
    }

    public function recordFailedLogin(?int $userId, int $failureCount, string $now, string $lockedUntil): void
    {
        if ($userId === null) {
            return;
        }
        $statement = $this->pdo->prepare(
            'UPDATE users SET failed_login_count = :failure_count,
                    locked_until = IF(:should_lock = 1, :locked_until, NULL),
                    updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'failure_count' => $failureCount,
            'should_lock' => $failureCount >= 5 ? 1 : 0,
            'locked_until' => $lockedUntil,
            'updated_at' => $now,
            'id' => $userId,
        ]);
    }

    public function recordSuccessfulLogin(int $userId, string $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = :last_login_at,
                    updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute(['last_login_at' => $now, 'updated_at' => $now, 'id' => $userId]);
    }

    public function recordAttempt(string $usernameHash, bool $successful, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (username_hash, successful, attempted_at) VALUES (:hash, :successful, :attempted_at)',
        );
        $statement->execute(['hash' => $usernameHash, 'successful' => $successful ? 1 : 0, 'attempted_at' => $now]);
    }

    public function recentFailures(string $usernameHash, string $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE username_hash = :hash AND successful = 0 AND attempted_at >= :since',
        );
        $statement->execute(['hash' => $usernameHash, 'since' => $since]);

        return (int) $statement->fetchColumn();
    }

    public function createSession(int $userId, string $tokenHash, string $csrfToken, string $now, string $idleExpires, string $absoluteExpires): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_sessions
             (user_id, token_hash, csrf_token, last_seen_at, idle_expires_at, absolute_expires_at, created_at)
             VALUES (:user_id, :token_hash, :csrf_token, :last_seen_at, :idle_expires_at, :absolute_expires_at, :created_at)',
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'csrf_token' => $csrfToken,
            'last_seen_at' => $now,
            'idle_expires_at' => $idleExpires,
            'absolute_expires_at' => $absoluteExpires,
            'created_at' => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function session(string $tokenHash, string $now): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id AS session_id, s.csrf_token, s.absolute_expires_at,
                    u.id, u.username, u.display_name, u.email, u.role, u.password_hash,
                    u.password_change_required, u.active
             FROM user_sessions s INNER JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :token_hash AND s.idle_expires_at > :idle_now
               AND s.absolute_expires_at > :absolute_now AND u.active = 1',
        );
        $statement->execute(['token_hash' => $tokenHash, 'idle_now' => $now, 'absolute_now' => $now]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function touchSession(int $sessionId, string $lastSeen, string $idleExpires): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_sessions SET last_seen_at = :last_seen_at, idle_expires_at = :idle_expires_at WHERE id = :id',
        );
        $statement->execute(['last_seen_at' => $lastSeen, 'idle_expires_at' => $idleExpires, 'id' => $sessionId]);
    }

    public function deleteSessionByToken(string $tokenHash): void
    {
        $statement = $this->pdo->prepare('DELETE FROM user_sessions WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);
    }

    public function revokeUserSessions(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM user_sessions WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
    }

    public function purgeExpired(string $now): void
    {
        $statement = $this->pdo->prepare('DELETE FROM user_sessions WHERE idle_expires_at <= :idle_now OR absolute_expires_at <= :absolute_now');
        $statement->execute(['idle_now' => $now, 'absolute_now' => $now]);
        $attempts = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff');
        $attempts->execute(['cutoff' => date('Y-m-d H:i:s.u', strtotime($now . ' -2 days'))]);
    }
}
