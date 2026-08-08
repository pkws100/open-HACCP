<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Api\ApiException;
use Haccp\Repository\AuthRepository;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\EventRepository;
use Haccp\Support\Clock;
use PDO;
use Throwable;

final readonly class EventWorkflowService
{
    public function __construct(
        private PDO $pdo,
        private EventRepository $events,
        private AuthRepository $users,
        private DeviceRepository $devices,
        private AuthService $auth,
        private AuditService $audit,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(?string $state, ?string $deviceUid, int $days): array
    {
        $days = in_array($days, [7, 30, 90, 366], true) ? $days : 30;
        $now = $this->clock->now();
        $rows = $this->events->list(
            $state,
            $deviceUid,
            $this->clock->database($now->modify(sprintf('-%d days', $days))),
            $this->clock->database($now),
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['action_count'] = (int) $row['action_count'];
            $row['metadata'] = $row['metadata_json'] === null ? null : json_decode((string) $row['metadata_json'], true);
            unset($row['metadata_json']);
        }

        return ['events' => $rows, 'filters' => ['state' => $state ?? 'all', 'device' => $deviceUid, 'days' => $days]];
    }

    /** @return array<string, mixed> */
    public function detail(int $eventId): array
    {
        $event = $this->events->byId($eventId);
        if ($event === null) {
            throw new ApiException(404, 'EVENT_NOT_FOUND', 'Die Abweichung wurde nicht gefunden.');
        }

        return [
            'event' => $event,
            'actions' => $this->events->actionsForEvent($eventId),
            'action_revisions' => $this->events->actionRevisionsForEvent($eventId),
        ];
    }

    /** @return array<string, mixed> */
    public function acknowledge(int $eventId, int $userId): array
    {
        if ($this->events->byId($eventId) === null) {
            throw new ApiException(404, 'EVENT_NOT_FOUND', 'Die Abweichung wurde nicht gefunden.');
        }
        $now = $this->clock->database($this->clock->now());
        $this->events->acknowledge($eventId, $userId, $now);
        $this->audit->append('event.acknowledged', $userId, 'compliance_event', (string) $eventId);

        return $this->detail($eventId);
    }

    /** @return array<string, mixed> */
    public function action(int $eventId, object $payload, int $userId): array
    {
        if ($this->events->byId($eventId) === null) {
            throw new ApiException(404, 'EVENT_NOT_FOUND', 'Die Abweichung wurde nicht gefunden.');
        }
        $cause = $this->required($payload->cause ?? null, 'cause');
        $actionTaken = $this->required($payload->action_taken ?? null, 'action_taken');
        $disposition = $this->required($payload->product_disposition ?? null, 'product_disposition');
        $followUp = $this->optional($payload->preventive_follow_up ?? null);
        $responsible = is_int($payload->responsible_user_id ?? null) ? $payload->responsible_user_id : $userId;
        if ($this->users->userById($responsible) === null) {
            throw new ApiException(422, 'ACTION_VALIDATION_FAILED', 'Die verantwortliche Person wurde nicht gefunden.');
        }
        $performedAt = is_string($payload->performed_at ?? null) ? $payload->performed_at : null;
        try {
            $performed = $performedAt === null ? $this->clock->now() : new \DateTimeImmutable($performedAt);
        } catch (\Throwable) {
            throw new ApiException(422, 'ACTION_VALIDATION_FAILED', 'Der Maßnahmenzeitpunkt ist ungültig.');
        }
        $now = $this->clock->database($this->clock->now());
        $this->pdo->beginTransaction();
        try {
            $actionId = $this->events->createAction($eventId, $userId, $now);
            $this->events->addActionRevision($actionId, 1, [
                'cause' => $cause,
                'action_taken' => $actionTaken,
                'product_disposition' => $disposition,
                'preventive_follow_up' => $followUp,
                'performed_at' => $this->clock->database($performed),
                'responsible_user_id' => $responsible,
                'created_by_user_id' => $userId,
                'created_at' => $now,
            ]);
            $this->events->markActionRecorded($eventId, $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->audit->append('corrective_action.recorded', $userId, 'corrective_action', (string) $actionId, ['event_id' => $eventId]);

        return $this->detail($eventId);
    }

    /** @return array<string, mixed> */
    public function reviseAction(int $actionId, object $payload, int $userId): array
    {
        $cause = $this->required($payload->cause ?? null, 'cause');
        $actionTaken = $this->required($payload->action_taken ?? null, 'action_taken');
        $disposition = $this->required($payload->product_disposition ?? null, 'product_disposition');
        $followUp = $this->optional($payload->preventive_follow_up ?? null);
        $responsible = is_int($payload->responsible_user_id ?? null) ? $payload->responsible_user_id : $userId;
        if ($this->users->userById($responsible) === null) {
            throw new ApiException(422, 'ACTION_VALIDATION_FAILED', 'Die verantwortliche Person wurde nicht gefunden.');
        }
        $performedAt = is_string($payload->performed_at ?? null) ? $payload->performed_at : null;
        try {
            $performed = $performedAt === null ? $this->clock->now() : new \DateTimeImmutable($performedAt);
        } catch (\Throwable) {
            throw new ApiException(422, 'ACTION_VALIDATION_FAILED', 'Der Maßnahmenzeitpunkt ist ungültig.');
        }
        $expected = is_int($payload->expected_revision ?? null) ? $payload->expected_revision : 0;
        $now = $this->clock->database($this->clock->now());
        $this->pdo->beginTransaction();
        try {
            $action = $this->events->action($actionId);
            if ($action === null) {
                throw new ApiException(404, 'ACTION_NOT_FOUND', 'Die Maßnahme wurde nicht gefunden.');
            }
            if ((string) $action['state'] !== 'recorded') {
                throw new ApiException(409, 'ACTION_IMMUTABLE', 'Eine geprüfte Maßnahme kann nicht mehr korrigiert werden.');
            }
            if ((int) $action['current_revision'] !== $expected) {
                throw new ApiException(409, 'ACTION_REVISION_CONFLICT', 'Die Maßnahme wurde zwischenzeitlich geändert.');
            }
            $revision = $expected + 1;
            $this->events->addActionRevision($actionId, $revision, [
                'cause' => $cause,
                'action_taken' => $actionTaken,
                'product_disposition' => $disposition,
                'preventive_follow_up' => $followUp,
                'performed_at' => $this->clock->database($performed),
                'responsible_user_id' => $responsible,
                'created_by_user_id' => $userId,
                'created_at' => $now,
            ]);
            $this->events->advanceActionRevision($actionId, $revision, $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->audit->append('corrective_action.revised', $userId, 'corrective_action', (string) $actionId, ['revision' => $revision]);

        return $this->detail((int) $action['event_id']);
    }

    /** @return array<string, mixed> */
    public function verify(int $actionId, object $payload, array $user): array
    {
        $password = is_string($payload->password ?? null) ? $payload->password : '';
        if (!$this->auth->verifyPassword($user, $password)) {
            throw new ApiException(422, 'CURRENT_PASSWORD_INVALID', 'Das Passwort zur Bestätigung ist nicht korrekt.');
        }
        $note = $this->required($payload->note ?? null, 'note');
        $now = $this->clock->database($this->clock->now());
        $this->pdo->beginTransaction();
        try {
            $action = $this->events->action($actionId);
            if ($action === null) {
                throw new ApiException(404, 'ACTION_NOT_FOUND', 'Die Maßnahme wurde nicht gefunden.');
            }
            if ((string) $action['state'] === 'verified') {
                throw new ApiException(409, 'ACTION_ALREADY_VERIFIED', 'Die Maßnahme wurde bereits geprüft.');
            }
            $this->events->verifyAction($actionId, (int) $user['id'], $note, $now);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->audit->append('corrective_action.verified', (int) $user['id'], 'corrective_action', (string) $actionId);

        return $this->detail((int) $action['event_id']);
    }

    /** @return array<string, mixed> */
    public function batteryReplaced(string $deviceUid, object $payload, int $userId): array
    {
        $device = $this->devices->findByUid($deviceUid);
        if ($device === null) {
            throw new ApiException(404, 'DEVICE_NOT_FOUND', 'Das Gerät wurde nicht gefunden.');
        }
        $chemistry = trim(is_string($payload->chemistry ?? null) ? $payload->chemistry : 'unspecified');
        $series = is_int($payload->series_count ?? null) ? $payload->series_count : 1;
        $capacity = is_int($payload->nominal_capacity_mah ?? null) ? $payload->nominal_capacity_mah : null;
        if ($chemistry === '' || mb_strlen($chemistry) > 64 || $series < 1 || $series > 16 || ($capacity !== null && ($capacity < 1 || $capacity > 100000))) {
            throw new ApiException(422, 'BATTERY_CYCLE_INVALID', 'Die Batterieangaben sind ungültig.');
        }
        $now = $this->clock->database($this->clock->now());
        $id = $this->events->recordBatteryCycle($device->id, $userId, [
            'started_at' => $now,
            'chemistry' => $chemistry,
            'series_count' => $series,
            'nominal_capacity_mah' => $capacity,
            'forecast_enabled' => ($payload->forecast_enabled ?? true) === true ? 1 : 0,
            'created_at' => $now,
        ]);
        $this->audit->append('battery.replaced', $userId, 'device', $deviceUid, ['cycle_id' => $id]);

        return ['success' => true, 'battery_cycle_id' => $id, 'started_at' => $now];
    }

    private function required(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 4000) {
            throw new ApiException(422, 'ACTION_VALIDATION_FAILED', 'Ein Pflichtfeld der Maßnahme fehlt oder ist zu lang.', ['field' => $field]);
        }

        return trim($value);
    }

    private function optional(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->required($value, 'preventive_follow_up');
    }
}
