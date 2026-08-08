<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class MeasurementPointRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findActiveByDeviceAndCode(int $deviceId, string $code): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, device_id, code, name, sensor_type FROM measurement_points
             WHERE device_id = :device_id AND code = :code AND active = 1',
        );
        $statement->execute(['device_id' => $deviceId, 'code' => $code]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByDeviceAndCode(int $deviceId, string $code): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, device_id, code, name, sensor_type, active FROM measurement_points
             WHERE device_id = :device_id AND code = :code',
        );
        $statement->execute(['device_id' => $deviceId, 'code' => $code]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $values */
    public function updateDemo(int $measurementPointId, array $values): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE measurement_points
             SET name = :name, sensor_type = :sensor_type, location = :location, active = 1,
                 updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'name' => $values['name'],
            'sensor_type' => $values['sensor_type'],
            'location' => $values['location'],
            'updated_at' => $values['updated_at'],
            'id' => $measurementPointId,
        ]);
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO measurement_points
             (device_id, code, name, sensor_type, location, active, temperature_min_c, temperature_max_c,
              humidity_min_rh, humidity_max_rh, created_at, updated_at)
             VALUES
             (:device_id, :code, :name, :sensor_type, :location, 1, :temperature_min_c, :temperature_max_c,
              :humidity_min_rh, :humidity_max_rh, :created_at, :updated_at)',
        );
        $statement->execute($values);

        return (int) $this->pdo->lastInsertId();
    }
}
