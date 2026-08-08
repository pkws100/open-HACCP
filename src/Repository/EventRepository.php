<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class EventRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function openEvent(int $deviceId, ?int $pointId, string $type): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM compliance_events WHERE device_id = :device_id
             AND ((:point_null IS NULL AND measurement_point_id IS NULL) OR measurement_point_id = :point_id)
             AND event_type = :event_type AND state <> \'resolved\' AND closed_at IS NULL
             ORDER BY id DESC LIMIT 1 FOR UPDATE',
        );
        $statement->execute(['device_id' => $deviceId, 'point_null' => $pointId, 'point_id' => $pointId, 'event_type' => $type]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO compliance_events
             (device_id, measurement_point_id, event_type, severity, state, opened_at, closed_at,
              threshold_min, threshold_max, observed_value, source_measurement_id,
              source_transmission_id, metadata_json, created_at, updated_at)
             VALUES (:device_id, :measurement_point_id, :event_type, :severity, :state, :opened_at, NULL,
                     :threshold_min, :threshold_max, :observed_value, :source_measurement_id,
                     :source_transmission_id, :metadata_json, :created_at, :updated_at)',
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function close(int $id, string $now): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE compliance_events SET closed_at = :closed_at,
                    state = IF(state = 'verified', 'resolved', state), updated_at = :updated_at WHERE id = :id",
        );
        $statement->execute(['closed_at' => $now, 'updated_at' => $now, 'id' => $id]);
    }

    public function updateObserved(int $id, float|int $value, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE compliance_events SET observed_value = :value, updated_at = :now WHERE id = :id');
        $statement->execute(['value' => $value, 'now' => $now, 'id' => $id]);
    }

    public function updateMetadata(int $id, array $metadata, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE compliance_events SET metadata_json = :metadata_json, updated_at = :now WHERE id = :id');
        $statement->execute([
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'now' => $now,
            'id' => $id,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function byId(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.*, d.device_uid, d.name AS device_name, mp.code AS point_code, mp.name AS point_name,
                    ack.display_name AS acknowledged_by
             FROM compliance_events e INNER JOIN devices d ON d.id = e.device_id
             LEFT JOIN measurement_points mp ON mp.id = e.measurement_point_id
             LEFT JOIN users ack ON ack.id = e.acknowledged_by_user_id WHERE e.id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $state, ?string $deviceUid, string $from, string $to): array
    {
        $where = ['e.opened_at >= :from', 'e.opened_at <= :to'];
        $params = ['from' => $from, 'to' => $to];
        if ($state !== null && $state !== 'all') {
            $where[] = 'e.state = :state';
            $params['state'] = $state;
        }
        if ($deviceUid !== null && $deviceUid !== '') {
            $where[] = 'd.device_uid = :device_uid';
            $params['device_uid'] = $deviceUid;
        }
        $statement = $this->pdo->prepare(
            'SELECT e.*, d.device_uid, d.name AS device_name, mp.code AS point_code, mp.name AS point_name,
                    ack.display_name AS acknowledged_by,
                    (SELECT COUNT(*) FROM corrective_actions ca WHERE ca.event_id = e.id) AS action_count
             FROM compliance_events e INNER JOIN devices d ON d.id = e.device_id
             LEFT JOIN measurement_points mp ON mp.id = e.measurement_point_id
             LEFT JOIN users ack ON ack.id = e.acknowledged_by_user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY e.opened_at DESC LIMIT 1000',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function acknowledge(int $eventId, int $userId, string $now): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE compliance_events SET state = IF(state = 'open', 'acknowledged', state),
                    acknowledged_at = COALESCE(acknowledged_at, :acknowledged_at),
                    acknowledged_by_user_id = COALESCE(acknowledged_by_user_id, :user_id), updated_at = :updated_at
             WHERE id = :id AND state <> 'resolved'",
        );
        $statement->execute(['acknowledged_at' => $now, 'updated_at' => $now, 'user_id' => $userId, 'id' => $eventId]);

        return $statement->rowCount() > 0;
    }

    public function createAction(int $eventId, int $userId, string $now): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO corrective_actions (event_id, current_revision, state, created_by_user_id, created_at, updated_at)
             VALUES (:event_id, 1, 'recorded', :user_id, :created_at, :updated_at)",
        );
        $statement->execute(['event_id' => $eventId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addActionRevision(int $actionId, int $revision, array $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO corrective_action_revisions
             (corrective_action_id, revision, cause, action_taken, product_disposition, preventive_follow_up,
              performed_at, responsible_user_id, created_by_user_id, created_at)
             VALUES (:corrective_action_id, :revision, :cause, :action_taken, :product_disposition,
                     :preventive_follow_up, :performed_at, :responsible_user_id, :created_by_user_id, :created_at)',
        );
        $statement->execute(['corrective_action_id' => $actionId, 'revision' => $revision] + $data);
    }

    public function markActionRecorded(int $eventId, string $now): void
    {
        $statement = $this->pdo->prepare("UPDATE compliance_events SET state = 'action_recorded', updated_at = :now WHERE id = :id");
        $statement->execute(['now' => $now, 'id' => $eventId]);
    }

    /** @return array<string, mixed>|null */
    public function action(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM corrective_actions WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function advanceActionRevision(int $actionId, int $revision, string $now): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE corrective_actions SET current_revision = :revision, updated_at = :now
             WHERE id = :id AND state = 'recorded'",
        );
        $statement->execute(['revision' => $revision, 'now' => $now, 'id' => $actionId]);
    }

    /** @return list<array<string, mixed>> */
    public function actionsForEvent(int $eventId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ca.id, ca.state, ca.current_revision, ca.created_at,
                    r.cause, r.action_taken, r.product_disposition, r.preventive_follow_up, r.performed_at,
                    responsible.display_name AS responsible_name, creator.display_name AS created_by,
                    v.verified_at, v.note AS verification_note, verifier.display_name AS verified_by
             FROM corrective_actions ca
             INNER JOIN corrective_action_revisions r ON r.corrective_action_id = ca.id AND r.revision = ca.current_revision
             INNER JOIN users responsible ON responsible.id = r.responsible_user_id
             INNER JOIN users creator ON creator.id = r.created_by_user_id
             LEFT JOIN event_verifications v ON v.id = (SELECT latest.id FROM event_verifications latest WHERE latest.corrective_action_id = ca.id ORDER BY latest.id DESC LIMIT 1)
             LEFT JOIN users verifier ON verifier.id = v.verified_by_user_id
             WHERE ca.event_id = :event_id ORDER BY ca.created_at',
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function actionRevisionsForEvent(int $eventId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.corrective_action_id, r.revision, r.cause, r.action_taken, r.product_disposition,
                    r.preventive_follow_up, r.performed_at, r.created_at,
                    responsible.display_name AS responsible_name, creator.display_name AS created_by
             FROM corrective_action_revisions r
             INNER JOIN corrective_actions ca ON ca.id = r.corrective_action_id
             INNER JOIN users responsible ON responsible.id = r.responsible_user_id
             INNER JOIN users creator ON creator.id = r.created_by_user_id
             WHERE ca.event_id = :event_id ORDER BY r.corrective_action_id, r.revision',
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll();
    }

    public function verifyAction(int $actionId, int $userId, string $note, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO event_verifications (corrective_action_id, verified_by_user_id, verified_at, note, created_at)
             VALUES (:action_id, :user_id, :verified_at, :note, :created_at)',
        );
        $statement->execute(['action_id' => $actionId, 'user_id' => $userId, 'verified_at' => $now, 'created_at' => $now, 'note' => $note]);
        $this->pdo->prepare("UPDATE corrective_actions SET state = 'verified', updated_at = :now WHERE id = :id")
            ->execute(['now' => $now, 'id' => $actionId]);
        $this->pdo->prepare(
            "UPDATE compliance_events e INNER JOIN corrective_actions a ON a.event_id = e.id
             SET e.state = IF(e.closed_at IS NULL, 'verified', 'resolved'), e.updated_at = :now WHERE a.id = :id",
        )->execute(['now' => $now, 'id' => $actionId]);
    }

    public function recordBatteryCycle(int $deviceId, int $userId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO battery_cycles
             (device_id, started_at, chemistry, series_count, nominal_capacity_mah, forecast_enabled,
              recorded_by_user_id, created_at)
             VALUES (:device_id, :started_at, :chemistry, :series_count, :nominal_capacity_mah,
                     :forecast_enabled, :recorded_by_user_id, :created_at)',
        );
        $statement->execute(['device_id' => $deviceId, 'recorded_by_user_id' => $userId] + $data);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function latestBatteryCycle(int $deviceId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM battery_cycles WHERE device_id = :device_id ORDER BY started_at DESC LIMIT 1');
        $statement->execute(['device_id' => $deviceId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
