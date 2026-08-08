<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Support\Clock;
use PDO;
use RuntimeException;
use Throwable;

final readonly class AuditService
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private PDO $pdo, private Clock $clock, private string $key)
    {
    }

    /** @param array<string, mixed> $payload */
    public function append(string $action, ?int $actorUserId = null, ?string $entityType = null, ?string $entityId = null, array $payload = []): string
    {
        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }
        try {
            $head = $this->pdo->query('SELECT head_hash FROM audit_chain_state WHERE id = 1 FOR UPDATE')->fetchColumn();
            $previous = is_string($head) ? $head : self::GENESIS;
            $occurredAt = $this->clock->database($this->clock->now());
            ksort($payload);
            $payloadJson = $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $canonical = implode('|', [
                $previous,
                $occurredAt,
                (string) ($actorUserId ?? ''),
                $action,
                (string) ($entityType ?? ''),
                (string) ($entityId ?? ''),
                (string) ($payloadJson ?? ''),
            ]);
            $entryHash = hash_hmac('sha256', $canonical, $this->key);
            $statement = $this->pdo->prepare(
                'INSERT INTO audit_log
                 (actor_user_id, action, entity_type, entity_id, payload_json, previous_hash, entry_hash, occurred_at)
                 VALUES (:actor, :action, :entity_type, :entity_id, :payload, :previous_hash, :entry_hash, :occurred_at)',
            );
            $statement->execute([
                'actor' => $actorUserId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'payload' => $payloadJson,
                'previous_hash' => $previous,
                'entry_hash' => $entryHash,
                'occurred_at' => $occurredAt,
            ]);
            $update = $this->pdo->prepare('UPDATE audit_chain_state SET head_hash = :hash, updated_at = :now WHERE id = 1');
            $update->execute(['hash' => $entryHash, 'now' => $occurredAt]);
            if ($started) {
                $this->pdo->commit();
            }

            return $entryHash;
        } catch (Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{valid: bool, entries: int, head_hash: string, invalid_id: int|null} */
    public function verify(): array
    {
        $previous = self::GENESIS;
        $count = 0;
        foreach ($this->pdo->query('SELECT * FROM audit_log ORDER BY id')->fetchAll() as $row) {
            $canonical = implode('|', [
                $previous,
                $row['occurred_at'],
                (string) ($row['actor_user_id'] ?? ''),
                $row['action'],
                (string) ($row['entity_type'] ?? ''),
                (string) ($row['entity_id'] ?? ''),
                (string) ($row['payload_json'] ?? ''),
            ]);
            $expected = hash_hmac('sha256', $canonical, $this->key);
            if (!hash_equals($previous, (string) $row['previous_hash']) || !hash_equals($expected, (string) $row['entry_hash'])) {
                return ['valid' => false, 'entries' => $count, 'head_hash' => $previous, 'invalid_id' => (int) $row['id']];
            }
            $previous = $expected;
            $count++;
        }
        $head = (string) $this->pdo->query('SELECT head_hash FROM audit_chain_state WHERE id = 1')->fetchColumn();
        if (!hash_equals($previous, $head)) {
            throw new RuntimeException('Audit chain head does not match the calculated value.');
        }

        return ['valid' => true, 'entries' => $count, 'head_hash' => $head, 'invalid_id' => null];
    }

    public function head(): string
    {
        return (string) $this->pdo->query('SELECT head_hash FROM audit_chain_state WHERE id = 1')->fetchColumn();
    }
}
