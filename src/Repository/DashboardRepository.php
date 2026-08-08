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
                    d.last_seen_at, d.last_rssi_dbm, d.last_battery_mv, COUNT(mp.id) AS measurement_point_count
             FROM devices d
             LEFT JOIN measurement_points mp ON mp.device_id = d.id AND mp.active = 1
             GROUP BY d.id
             ORDER BY (d.status = \'active\') DESC, d.name, d.device_uid',
        );

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function deviceByUid(string $uid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, device_uid, name, status, hardware_revision, firmware_version,
                    last_seen_at, last_rssi_dbm, last_battery_mv
             FROM devices WHERE device_uid = :uid',
        );
        $statement->execute(['uid' => $uid]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function measurementPoints(int $deviceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, name, sensor_type, location, temperature_min_c, temperature_max_c,
                    humidity_min_rh, humidity_max_rh
             FROM measurement_points
             WHERE device_id = :device_id AND active = 1
             ORDER BY name, code',
        );
        $statement->execute(['device_id' => $deviceId]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function fleetSummary(string $cutoff, string $staleCutoff): array
    {
        $devices = $this->pdo->prepare(
            'SELECT COUNT(*) AS total_devices,
                    SUM(status = \'active\') AS active_devices,
                    SUM(status = \'active\' AND (last_seen_at IS NULL OR last_seen_at < :stale_cutoff)) AS stale_devices
             FROM devices',
        );
        $devices->execute(['stale_cutoff' => $staleCutoff]);
        $result = $devices->fetch();

        $measurements = $this->pdo->prepare('SELECT COUNT(*) FROM measurements WHERE measured_at >= :cutoff');
        $measurements->execute(['cutoff' => $cutoff]);
        $result['measurements_in_window'] = (int) $measurements->fetchColumn();

        return $result;
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
                    accepted_count, duplicate_count, rejected_count
             FROM device_transmissions WHERE device_id = :device_id
             ORDER BY received_at DESC, id DESC LIMIT 1',
        );
        $statement->execute(['device_id' => $deviceId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
