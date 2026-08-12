<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class DashboardRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function devices(): array
    {
        $statement = $this->pdo->query(
            'SELECT d.id, d.device_uid, d.name, d.status, d.hardware_revision, d.firmware_version,
                    d.device_info_json, d.last_applied_config_version, d.last_config_status,
                    d.last_seen_at, d.last_rssi_dbm, d.last_battery_mv,
                    (SELECT COUNT(*) FROM measurement_points mp WHERE mp.device_id = d.id AND mp.active = 1) AS measurement_point_count,
                    dc.config_version, dc.alarm_enabled, dc.temperature_min_c, dc.temperature_max_c,
                    dc.battery_low_mv, dc.battery_full_mv, dc.measurement_interval_seconds,
                    dc.upload_interval_seconds, dc.config_json,
                    photo.public_id AS photo_public_id, photo.revision AS photo_revision,
                    photo.width AS photo_width, photo.height AS photo_height,
                    photo.created_at AS photo_created_at
             FROM devices d
             INNER JOIN device_configs dc ON dc.device_id = d.id
                AND dc.config_version = (SELECT MAX(latest.config_version) FROM device_configs latest WHERE latest.device_id = d.id)
             LEFT JOIN measurement_points representative ON representative.id = (
                 SELECT candidate.id FROM measurement_points candidate
                 WHERE candidate.device_id = d.id AND candidate.active = 1
                 ORDER BY candidate.name, candidate.code, candidate.id LIMIT 1
             )
             LEFT JOIN measurement_point_photos photo ON photo.measurement_point_id = representative.id
                AND photo.is_current = 1 AND photo.deleted_at IS NULL
             WHERE d.status = \'active\'
             ORDER BY d.name, d.device_uid',
        );

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function deviceByUid(string $uid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.device_uid, d.name, d.status, d.hardware_revision, d.firmware_version,
                    d.device_info_json, d.last_applied_config_version, d.last_config_status,
                    d.last_seen_at, d.last_rssi_dbm, d.last_battery_mv,
                    dc.config_version, dc.alarm_enabled, dc.temperature_min_c, dc.temperature_max_c,
                    dc.battery_low_mv, dc.battery_full_mv, dc.measurement_interval_seconds,
                    dc.upload_interval_seconds, dc.config_json,
                    photo.public_id AS photo_public_id, photo.revision AS photo_revision,
                    photo.width AS photo_width, photo.height AS photo_height,
                    photo.created_at AS photo_created_at
             FROM devices d
             INNER JOIN device_configs dc ON dc.device_id = d.id
                AND dc.config_version = (SELECT MAX(latest.config_version) FROM device_configs latest WHERE latest.device_id = d.id)
             LEFT JOIN measurement_points representative ON representative.id = (
                 SELECT candidate.id FROM measurement_points candidate
                 WHERE candidate.device_id = d.id AND candidate.active = 1
                 ORDER BY candidate.name, candidate.code, candidate.id LIMIT 1
             )
             LEFT JOIN measurement_point_photos photo ON photo.measurement_point_id = representative.id
                AND photo.is_current = 1 AND photo.deleted_at IS NULL
             WHERE d.device_uid = :uid AND d.status = \'active\'',
        );
        $statement->execute(['uid' => $uid]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function measurementPoints(int $deviceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT mp.id, mp.code, mp.name, mp.sensor_type, mp.location, mp.temperature_min_c, mp.temperature_max_c,
                    mp.humidity_min_rh, mp.humidity_max_rh,
                    photo.public_id AS photo_public_id, photo.revision AS photo_revision,
                    photo.width AS photo_width, photo.height AS photo_height,
                    photo.created_at AS photo_created_at
             FROM measurement_points mp
             LEFT JOIN measurement_point_photos photo ON photo.measurement_point_id = mp.id
                AND photo.is_current = 1 AND photo.deleted_at IS NULL
             WHERE mp.device_id = :device_id AND mp.active = 1
             ORDER BY mp.name, mp.code',
        );
        $statement->execute(['device_id' => $deviceId]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function fleetSummary(string $cutoff, string $staleCutoff): array
    {
        $devices = $this->pdo->prepare(
            'SELECT COUNT(*) AS total_devices,
                    COUNT(*) AS active_devices,
                    SUM(last_seen_at IS NULL OR last_seen_at < :stale_cutoff) AS stale_devices
             FROM devices WHERE status = \'active\'',
        );
        $devices->execute(['stale_cutoff' => $staleCutoff]);
        $result = $devices->fetch();

        $measurements = $this->pdo->prepare(
            'SELECT COUNT(*) FROM measurements m
             INNER JOIN devices d ON d.id = m.device_id
             WHERE d.status = \'active\' AND m.measured_at >= :cutoff',
        );
        $measurements->execute(['cutoff' => $cutoff]);
        $result['measurements_in_window'] = (int) $measurements->fetchColumn();

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function latestMeasurementsForDevice(int $deviceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT mp.code, m.temperature_c, m.measured_at
             FROM measurement_points mp
             LEFT JOIN measurements m ON m.id = (
                 SELECT latest.id FROM measurements latest
                 WHERE latest.measurement_point_id = mp.id
                 ORDER BY latest.measured_at DESC, latest.sequence DESC LIMIT 1
             )
             WHERE mp.device_id = :device_id AND mp.active = 1
             ORDER BY mp.id',
        );
        $statement->execute(['device_id' => $deviceId]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function pointSummary(int $measurementPointId, string $cutoff): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS measurement_count,
                    AVG(temperature_c) AS average_temperature_c,
                    MIN(temperature_c) AS minimum_temperature_c,
                    MAX(temperature_c) AS maximum_temperature_c,
                    AVG(humidity_rh) AS average_humidity_rh
             FROM measurements
             WHERE measurement_point_id = :measurement_point_id AND measured_at >= :cutoff',
        );
        $statement->execute(['measurement_point_id' => $measurementPointId, 'cutoff' => $cutoff]);

        return $statement->fetch();
    }

    /** @return array<string, mixed>|null */
    public function latestMeasurement(int $measurementPointId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT sequence, measured_at, received_at, temperature_c, humidity_rh, battery_mv
             FROM measurements WHERE measurement_point_id = :measurement_point_id
             ORDER BY measured_at DESC, sequence DESC LIMIT 1',
        );
        $statement->execute(['measurement_point_id' => $measurementPointId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function series(int $measurementPointId, string $cutoff): array
    {
        $statement = $this->pdo->prepare(
            'SELECT sequence, measured_at, temperature_c, humidity_rh, battery_mv
             FROM measurements
             WHERE measurement_point_id = :measurement_point_id AND measured_at >= :cutoff
             ORDER BY measured_at ASC, sequence ASC
             LIMIT 2500',
        );
        $statement->execute(['measurement_point_id' => $measurementPointId, 'cutoff' => $cutoff]);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function recentMeasurements(int $measurementPointId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT sequence, measured_at, received_at, temperature_c, humidity_rh, battery_mv
             FROM measurements WHERE measurement_point_id = :measurement_point_id
             ORDER BY measured_at DESC, sequence DESC LIMIT 20',
        );
        $statement->execute(['measurement_point_id' => $measurementPointId]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function latestTransmission(int $deviceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT transmission_type, batch_id, received_at, firmware_version, hardware_revision,
                    battery_mv, rssi_dbm, wifi_connect_ms, boot_count, measurement_count,
                    accepted_count, duplicate_count, rejected_count, device_info_json,
                    operational_status_json, applied_config_version, config_apply_status,
                    queue_depth, wifi_failures_since_report, upload_failures_since_report,
                    sleep_fallbacks_since_report, diagnostic_errors_json
             FROM device_transmissions WHERE device_id = :device_id
             ORDER BY received_at DESC, id DESC LIMIT 1',
        );
        $statement->execute(['device_id' => $deviceId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
