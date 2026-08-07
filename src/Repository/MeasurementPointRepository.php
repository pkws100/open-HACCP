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
