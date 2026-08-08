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
        $this->createInitial($deviceId, [
            'alarm_enabled' => false,
            'temperature_min_c' => null,
            'temperature_max_c' => null,
            'battery_low_mv' => 5600,
            'battery_full_mv' => 6000,
        ], $now);
    }

    /** @param array<string, mixed> $settings */
    public function createInitial(int $deviceId, array $settings, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO device_configs
             (device_id, config_version, measurement_interval_seconds, upload_interval_seconds,
              max_batch_size, alarm_enabled, temperature_min_c, temperature_max_c,
              battery_low_mv, battery_full_mv, created_at, updated_at)
             VALUES (:device_id, 1, 300, 21600, 500, :alarm_enabled, :temperature_min_c, :temperature_max_c,
                     :battery_low_mv, :battery_full_mv, :created_at, :updated_at)',
        );
        $statement->execute([
            'device_id' => $deviceId,
            'alarm_enabled' => $settings['alarm_enabled'] ? 1 : 0,
            'temperature_min_c' => $settings['temperature_min_c'],
            'temperature_max_c' => $settings['temperature_max_c'],
            'battery_low_mv' => $settings['battery_low_mv'],
            'battery_full_mv' => $settings['battery_full_mv'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function latest(int $deviceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT config_version, measurement_interval_seconds, upload_interval_seconds, max_batch_size,
                    alarm_enabled, temperature_min_c, temperature_max_c, battery_low_mv, battery_full_mv, config_json
             FROM device_configs WHERE device_id = :device_id ORDER BY config_version DESC LIMIT 1',
        );
        $statement->execute(['device_id' => $deviceId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function latestForUpdate(int $deviceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT config_version, measurement_interval_seconds, upload_interval_seconds, max_batch_size,
                    alarm_enabled, temperature_min_c, temperature_max_c, battery_low_mv, battery_full_mv, config_json
             FROM device_configs WHERE device_id = :device_id ORDER BY config_version DESC LIMIT 1 FOR UPDATE',
        );
        $statement->execute(['device_id' => $deviceId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $previous @param array<string, mixed> $settings */
    public function createNext(int $deviceId, array $previous, array $settings, string $now): int
    {
        $version = (int) $previous['config_version'] + 1;
        $statement = $this->pdo->prepare(
            'INSERT INTO device_configs
             (device_id, config_version, measurement_interval_seconds, upload_interval_seconds, max_batch_size,
              alarm_enabled, temperature_min_c, temperature_max_c, battery_low_mv, battery_full_mv,
              config_json, created_at, updated_at)
             VALUES
             (:device_id, :config_version, :measurement_interval_seconds, :upload_interval_seconds, :max_batch_size,
              :alarm_enabled, :temperature_min_c, :temperature_max_c, :battery_low_mv, :battery_full_mv,
              :config_json, :created_at, :updated_at)',
        );
        $statement->execute([
            'device_id' => $deviceId,
            'config_version' => $version,
            'measurement_interval_seconds' => $previous['measurement_interval_seconds'],
            'upload_interval_seconds' => $previous['upload_interval_seconds'],
            'max_batch_size' => $previous['max_batch_size'],
            'alarm_enabled' => $settings['alarm_enabled'] ? 1 : 0,
            'temperature_min_c' => $settings['temperature_min_c'],
            'temperature_max_c' => $settings['temperature_max_c'],
            'battery_low_mv' => $settings['battery_low_mv'],
            'battery_full_mv' => $settings['battery_full_mv'],
            'config_json' => $previous['config_json'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $version;
    }
}
