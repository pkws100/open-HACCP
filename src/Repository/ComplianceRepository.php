<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class ComplianceRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function establishment(): array
    {
        $row = $this->pdo->query(
            'SELECT e.*, u.display_name AS responsible_name
             FROM establishments e LEFT JOIN users u ON u.id = e.haccp_responsible_user_id WHERE e.id = 1',
        )->fetch();

        return $row === false ? [] : $row;
    }

    public function updateEstablishment(array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE establishments SET legal_name = :legal_name, trade_name = :trade_name,
                    address_line1 = :address_line1, address_line2 = :address_line2,
                    postal_code = :postal_code, city = :city, country_code = :country_code,
                    authority_reference = :authority_reference, timezone = :timezone,
                    haccp_responsible_user_id = :haccp_responsible_user_id,
                    general_retention_months = :general_retention_months, updated_at = :updated_at
             WHERE id = 1',
        );
        $statement->execute($data);
    }

    /** @return list<array<string, mixed>> */
    public function pointsWithCurrentConfig(): array
    {
        return $this->pdo->query(
            'SELECT mp.id, mp.code, mp.name, mp.location, mp.sensor_type, d.device_uid, d.name AS device_name,
                    c.config_version, c.legal_profile, c.control_classification, c.monitoring_purpose,
                    c.humidity_is_critical, c.retention_months, c.responsible_user_id,
                    u.display_name AS responsible_name, c.instrument_manufacturer, c.instrument_model,
                    c.instrument_serial, c.conformity_status, c.conformity_reference,
                    c.calibration_reference, c.verification_reference,
                    c.calibrated_at, c.verification_due_at, c.effective_from,
                    dc.alarm_enabled, dc.temperature_min_c, dc.temperature_max_c,
                    dc.measurement_interval_seconds, dc.upload_interval_seconds
             FROM measurement_points mp INNER JOIN devices d ON d.id = mp.device_id
             LEFT JOIN measurement_point_compliance_configs c ON c.id = (
                 SELECT latest.id FROM measurement_point_compliance_configs latest
                 WHERE latest.measurement_point_id = mp.id ORDER BY latest.config_version DESC LIMIT 1
             )
             LEFT JOIN device_configs dc ON dc.id = (
                 SELECT latest_config.id FROM device_configs latest_config
                 WHERE latest_config.device_id = d.id ORDER BY latest_config.config_version DESC LIMIT 1
             )
             LEFT JOIN users u ON u.id = c.responsible_user_id
             WHERE mp.active = 1 AND d.status = \'active\' ORDER BY d.name, mp.name',
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function point(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT mp.*, d.device_uid, d.name AS device_name FROM measurement_points mp
             INNER JOIN devices d ON d.id = mp.device_id WHERE mp.id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function currentPointConfig(int $pointId, bool $lock = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM measurement_point_compliance_configs WHERE measurement_point_id = :point_id
             ORDER BY config_version DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
        );
        $statement->execute(['point_id' => $pointId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function insertPointConfig(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO measurement_point_compliance_configs
             (measurement_point_id, config_version, legal_profile, control_classification, monitoring_purpose,
              humidity_is_critical, retention_months, responsible_user_id, instrument_manufacturer,
              instrument_model, instrument_serial, conformity_status, conformity_reference,
              calibration_reference, verification_reference, calibrated_at, verification_due_at,
              effective_from, created_by_user_id, created_at)
             VALUES (:measurement_point_id, :config_version, :legal_profile, :control_classification,
                     :monitoring_purpose, :humidity_is_critical, :retention_months, :responsible_user_id,
                     :instrument_manufacturer, :instrument_model, :instrument_serial, :conformity_status,
                     :conformity_reference, :calibration_reference, :verification_reference,
                     :calibrated_at, :verification_due_at, :effective_from,
                     :created_by_user_id, :created_at)',
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function effectivePointConfig(int $pointId, string $at): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM measurement_point_compliance_configs
             WHERE measurement_point_id = :point_id AND effective_from <= :at
             ORDER BY effective_from DESC, config_version DESC LIMIT 1',
        );
        $statement->execute(['point_id' => $pointId, 'at' => $at]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function configurationHistory(int $pointId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM measurement_point_compliance_configs WHERE measurement_point_id = :point_id
             ORDER BY config_version',
        );
        $statement->execute(['point_id' => $pointId]);

        return $statement->fetchAll();
    }
}
