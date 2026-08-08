<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class ExportRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createJob(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO export_jobs
             (public_id, requested_by_user_id, status, mode, format, parameters_json, draft, created_at, updated_at)
             VALUES (:public_id, :requested_by_user_id, :status, :mode, :format, :parameters_json,
                     :draft, :created_at, :updated_at)',
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function job(string $publicId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT j.*, u.display_name AS requested_by FROM export_jobs j
             INNER JOIN users u ON u.id = j.requested_by_user_id WHERE j.public_id = :public_id',
        );
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function jobs(?int $userId = null): array
    {
        $sql = 'SELECT j.*, u.display_name AS requested_by FROM export_jobs j
                INNER JOIN users u ON u.id = j.requested_by_user_id';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE j.requested_by_user_id = :user_id';
            $params['user_id'] = $userId;
        }
        $sql .= ' ORDER BY j.created_at DESC LIMIT 100';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function claimNext(string $now): ?array
    {
        $this->pdo->beginTransaction();
        $row = $this->pdo->query("SELECT * FROM export_jobs WHERE status = 'queued' ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
        if ($row === false) {
            $this->pdo->commit();
            return null;
        }
        $statement = $this->pdo->prepare(
            "UPDATE export_jobs SET status = 'running', attempt_count = attempt_count + 1,
                    started_at = :started_at, updated_at = :updated_at WHERE id = :id",
        );
        $statement->execute(['started_at' => $now, 'updated_at' => $now, 'id' => $row['id']]);
        $this->pdo->commit();
        $row['status'] = 'running';
        $row['started_at'] = $now;
        $row['attempt_count'] = (int) $row['attempt_count'] + 1;

        return $row;
    }

    public function complete(int $id, array $file): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE export_jobs SET status = 'complete', file_name = :file_name, file_path = :file_path,
                    mime_type = :mime_type, file_size = :file_size, sha256 = :sha256,
                    audit_head_hash = :audit_head_hash, completed_at = :completed_at,
                    expires_at = :expires_at, error_code = NULL, error_message = NULL,
                    updated_at = :updated_at WHERE id = :id",
        );
        $statement->execute(['id' => $id] + $file);
    }

    public function fail(int $id, string $code, string $message, string $now): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE export_jobs SET status = 'failed', error_code = :code, error_message = :message,
                    completed_at = :completed_at, updated_at = :updated_at WHERE id = :id",
        );
        $statement->execute(['code' => $code, 'message' => mb_substr($message, 0, 500), 'completed_at' => $now, 'updated_at' => $now, 'id' => $id]);
    }

    public function retry(int $id, string $code, string $message, string $now): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE export_jobs SET status = 'queued', error_code = :code, error_message = :message,
                    started_at = NULL, updated_at = :now WHERE id = :id",
        );
        $statement->execute(['code' => $code, 'message' => mb_substr($message, 0, 500), 'now' => $now, 'id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function expireDue(string $now): array
    {
        $statement = $this->pdo->prepare("SELECT id, file_path FROM export_jobs WHERE status = 'complete' AND expires_at <= :now");
        $statement->execute(['now' => $now]);
        $rows = $statement->fetchAll();
        $update = $this->pdo->prepare("UPDATE export_jobs SET status = 'expired', file_path = NULL, updated_at = :now WHERE id = :id");
        foreach ($rows as $row) {
            $update->execute(['now' => $now, 'id' => $row['id']]);
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function measurements(array $parameters): array
    {
        [$filter, $params] = $this->measurementFilters($parameters, 'm');
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.sequence, m.measured_at, m.received_at, m.temperature_c, m.humidity_rh,
                    m.battery_mv, d.device_uid, d.name AS device_name, d.hardware_revision, d.firmware_version,
                    mp.id AS measurement_point_id, mp.code AS point_code, mp.name AS point_name,
                    mp.location, mp.sensor_type,
                    (SELECT dc.temperature_min_c FROM device_configs dc WHERE dc.device_id = m.device_id
                     AND dc.created_at <= m.measured_at ORDER BY dc.config_version DESC LIMIT 1) AS temperature_min_c,
                    (SELECT dc.temperature_max_c FROM device_configs dc WHERE dc.device_id = m.device_id
                     AND dc.created_at <= m.measured_at ORDER BY dc.config_version DESC LIMIT 1) AS temperature_max_c,
                    (SELECT dc.measurement_interval_seconds FROM device_configs dc WHERE dc.device_id = m.device_id
                     AND dc.created_at <= m.measured_at ORDER BY dc.config_version DESC LIMIT 1) AS measurement_interval_seconds,
                    (SELECT dc.config_json FROM device_configs dc WHERE dc.device_id = m.device_id
                     AND dc.created_at <= m.measured_at ORDER BY dc.config_version DESC LIMIT 1) AS historical_config_json,
                    (SELECT dc.battery_low_mv FROM device_configs dc WHERE dc.device_id = m.device_id
                     AND dc.created_at <= m.measured_at ORDER BY dc.config_version DESC LIMIT 1) AS battery_low_mv,
                    (SELECT cc.legal_profile FROM measurement_point_compliance_configs cc
                     WHERE cc.measurement_point_id = m.measurement_point_id AND cc.effective_from <= m.measured_at
                     ORDER BY cc.effective_from DESC, cc.config_version DESC LIMIT 1) AS legal_profile,
                    (SELECT cc.control_classification FROM measurement_point_compliance_configs cc
                     WHERE cc.measurement_point_id = m.measurement_point_id AND cc.effective_from <= m.measured_at
                     ORDER BY cc.effective_from DESC, cc.config_version DESC LIMIT 1) AS control_classification,
                    (SELECT cc.monitoring_purpose FROM measurement_point_compliance_configs cc
                     WHERE cc.measurement_point_id = m.measurement_point_id AND cc.effective_from <= m.measured_at
                     ORDER BY cc.effective_from DESC, cc.config_version DESC LIMIT 1) AS monitoring_purpose,
                    (SELECT cc.humidity_is_critical FROM measurement_point_compliance_configs cc
                     WHERE cc.measurement_point_id = m.measurement_point_id AND cc.effective_from <= m.measured_at
                     ORDER BY cc.effective_from DESC, cc.config_version DESC LIMIT 1) AS humidity_is_critical
             FROM measurements m INNER JOIN devices d ON d.id = m.device_id
             INNER JOIN measurement_points mp ON mp.id = m.measurement_point_id
             WHERE ' . $filter . ' ORDER BY m.measured_at, d.device_uid, mp.code, m.sequence LIMIT 500000',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function deviations(array $parameters): array
    {
        $where = ['e.opened_at >= :from', 'e.opened_at <= :to'];
        $params = ['from' => $parameters['from_db'], 'to' => $parameters['to_db']];
        $this->addListFilter($where, $params, 'd.device_uid', 'device', $parameters['device_uids'] ?? []);
        $this->addListFilter($where, $params, 'e.measurement_point_id', 'point', $parameters['measurement_point_ids'] ?? []);
        $statement = $this->pdo->prepare(
            'SELECT e.id, e.event_type, e.severity, e.state, e.opened_at, e.closed_at,
                    e.threshold_min, e.threshold_max, e.observed_value, d.device_uid, d.name AS device_name,
                    mp.code AS point_code, mp.name AS point_name, ack.display_name AS acknowledged_by,
                    e.acknowledged_at, r.cause, r.action_taken, r.product_disposition,
                    r.preventive_follow_up, r.performed_at, responsible.display_name AS responsible_name,
                    v.verified_at, v.note AS verification_note, verifier.display_name AS verified_by
             FROM compliance_events e INNER JOIN devices d ON d.id = e.device_id
             LEFT JOIN measurement_points mp ON mp.id = e.measurement_point_id
             LEFT JOIN users ack ON ack.id = e.acknowledged_by_user_id
             LEFT JOIN corrective_actions ca ON ca.id = (SELECT latest.id FROM corrective_actions latest WHERE latest.event_id = e.id ORDER BY latest.id DESC LIMIT 1)
             LEFT JOIN corrective_action_revisions r ON r.corrective_action_id = ca.id AND r.revision = ca.current_revision
             LEFT JOIN users responsible ON responsible.id = r.responsible_user_id
             LEFT JOIN event_verifications v ON v.id = (SELECT latest.id FROM event_verifications latest WHERE latest.corrective_action_id = ca.id ORDER BY latest.id DESC LIMIT 1)
             LEFT JOIN users verifier ON verifier.id = v.verified_by_user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY e.opened_at',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function transmissions(array $parameters): array
    {
        $where = ['t.received_at >= :from', 't.received_at <= :to'];
        $params = ['from' => $parameters['from_db'], 'to' => $parameters['to_db']];
        $this->addListFilter($where, $params, 'd.device_uid', 'device', $parameters['device_uids'] ?? []);
        $this->addPointDeviceFilter($where, $params, $parameters['measurement_point_ids'] ?? []);
        $statement = $this->pdo->prepare(
            'SELECT t.transmission_type, t.received_at, t.firmware_version, t.hardware_revision,
                    t.battery_mv, t.rssi_dbm, t.wifi_connect_ms, t.boot_count, t.measurement_count,
                    t.accepted_count, t.duplicate_count, t.rejected_count, t.diagnostic_errors_json,
                    d.device_uid, d.name AS device_name
             FROM device_transmissions t INNER JOIN devices d ON d.id = t.device_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY t.received_at LIMIT 500000',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function configurations(array $parameters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $this->addListFilter($where, $params, 'd.device_uid', 'device', $parameters['device_uids'] ?? []);
        $this->addPointDeviceFilter($where, $params, $parameters['measurement_point_ids'] ?? []);
        $this->addListFilter($where, $params, 'mp.id', 'point', $parameters['measurement_point_ids'] ?? []);
        $statement = $this->pdo->prepare(
            'SELECT d.device_uid, d.name AS device_name, mp.code AS point_code, mp.name AS point_name,
                    c.config_version, c.legal_profile, c.control_classification, c.monitoring_purpose,
                    c.humidity_is_critical, c.retention_months, u.display_name AS responsible_name,
                    c.instrument_manufacturer, c.instrument_model, c.instrument_serial,
                    c.conformity_status, c.conformity_reference, c.calibration_reference,
                    c.verification_reference, c.calibrated_at,
                    c.verification_due_at, c.effective_from
             FROM measurement_point_compliance_configs c
             INNER JOIN measurement_points mp ON mp.id = c.measurement_point_id
             INNER JOIN devices d ON d.id = mp.device_id LEFT JOIN users u ON u.id = c.responsible_user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY d.device_uid, mp.code, c.config_version',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function deviceConfigurations(array $parameters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $this->addListFilter($where, $params, 'd.device_uid', 'device', $parameters['device_uids'] ?? []);
        $this->addPointDeviceFilter($where, $params, $parameters['measurement_point_ids'] ?? []);
        $statement = $this->pdo->prepare(
            'SELECT d.device_uid, d.name AS device_name, c.config_version, c.created_at AS effective_from,
                    c.measurement_interval_seconds, c.upload_interval_seconds, c.max_batch_size,
                    c.alarm_enabled, c.temperature_min_c, c.temperature_max_c,
                    c.battery_low_mv, c.battery_full_mv, c.config_json
             FROM device_configs c INNER JOIN devices d ON d.id = c.device_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY d.device_uid, c.config_version',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function measurementFilters(array $parameters, string $alias): array
    {
        $where = [sprintf('%s.measured_at >= :from', $alias), sprintf('%s.measured_at <= :to', $alias)];
        $params = ['from' => $parameters['from_db'], 'to' => $parameters['to_db']];
        $this->addListFilter($where, $params, 'd.device_uid', 'device', $parameters['device_uids'] ?? []);
        $this->addListFilter($where, $params, $alias . '.measurement_point_id', 'point', $parameters['measurement_point_ids'] ?? []);

        return [implode(' AND ', $where), $params];
    }

    private function addListFilter(array &$where, array &$params, string $column, string $prefix, array $values): void
    {
        if ($values === []) {
            return;
        }
        $placeholders = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        $where[] = sprintf('%s IN (%s)', $column, implode(', ', $placeholders));
    }

    private function addPointDeviceFilter(array &$where, array &$params, array $pointIds): void
    {
        if ($pointIds === []) {
            return;
        }
        $placeholders = [];
        foreach (array_values($pointIds) as $index => $pointId) {
            $key = 'device_point' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $pointId;
        }
        $where[] = 'EXISTS (SELECT 1 FROM measurement_points selected_point'
            . ' WHERE selected_point.device_id = d.id AND selected_point.id IN (' . implode(', ', $placeholders) . '))';
    }
}
