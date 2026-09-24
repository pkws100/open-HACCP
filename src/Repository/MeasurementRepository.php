<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class MeasurementRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $deviceId, int $measurementPointId, int $sequence): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT measured_at, temperature_c, humidity_rh, battery_mv
             FROM measurements
             WHERE device_id = :device_id AND measurement_point_id = :measurement_point_id AND sequence = :sequence',
        );
        $statement->execute([
            'device_id' => $deviceId,
            'measurement_point_id' => $measurementPointId,
            'sequence' => $sequence,
        ]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $measurement */
    public function insert(int $deviceId, int $measurementPointId, array $measurement, string $receivedAt): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO measurements
             (device_id, measurement_point_id, sequence, measured_at, received_at,
              temperature_c, corrected_temperature_c, applied_temperature_offset_c, calibration_config_version,
              humidity_rh, battery_mv, created_at)
             VALUES
             (:device_id, :measurement_point_id, :sequence, :measured_at, :received_at,
              :temperature_c, :corrected_temperature_c, :applied_temperature_offset_c, :calibration_config_version,
              :humidity_rh, :battery_mv, :created_at)',
        );
        $statement->execute([
            'device_id' => $deviceId,
            'measurement_point_id' => $measurementPointId,
            'sequence' => $measurement['sequence'],
            'measured_at' => $measurement['measured_at_db'],
            'received_at' => $receivedAt,
            'temperature_c' => $measurement['temperature_c_db'],
            'corrected_temperature_c' => $measurement['corrected_temperature_c'],
            'applied_temperature_offset_c' => $measurement['applied_temperature_offset_c'],
            'calibration_config_version' => $measurement['calibration_config_version'],
            'humidity_rh' => $measurement['humidity_rh_db'],
            'battery_mv' => $measurement['battery_mv'],
            'created_at' => $receivedAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function maxSequence(int $deviceId, int $measurementPointId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(sequence) FROM measurements WHERE device_id = :device_id AND measurement_point_id = :measurement_point_id',
        );
        $statement->execute(['device_id' => $deviceId, 'measurement_point_id' => $measurementPointId]);
        $value = $statement->fetchColumn();

        return $value === null ? null : (int) $value;
    }

    /** @return array{measured_at: string, sequence: int}|null */
    public function latestForPoint(int $deviceId, int $measurementPointId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT measured_at, sequence FROM measurements
             WHERE device_id = :device_id AND measurement_point_id = :measurement_point_id
             ORDER BY measured_at DESC, sequence DESC LIMIT 1',
        );
        $statement->execute(['device_id' => $deviceId, 'measurement_point_id' => $measurementPointId]);
        $row = $statement->fetch();

        return $row === false ? null : ['measured_at' => (string) $row['measured_at'], 'sequence' => (int) $row['sequence']];
    }
}
