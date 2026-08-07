<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class DeviceConfigRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createDefault(int $deviceId, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO device_configs
             (device_id, config_version, measurement_interval_seconds, upload_interval_seconds,
              max_batch_size, alarm_enabled, created_at, updated_at)
             VALUES (:device_id, 1, 300, 21600, 500, 0, :created_at, :updated_at)',
        );
        $statement->execute(['device_id' => $deviceId, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @return array<string, mixed>|null */
    public function latest(int $deviceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT config_version, measurement_interval_seconds, upload_interval_seconds, max_batch_size,
                    alarm_enabled, temperature_min_c, temperature_max_c, config_json
             FROM device_configs WHERE device_id = :device_id ORDER BY config_version DESC LIMIT 1',
        );
        $statement->execute(['device_id' => $deviceId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
