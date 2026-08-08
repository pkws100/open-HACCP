<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Api\ApiException;
use Haccp\Repository\DashboardRepository;
use Haccp\Repository\DeviceConfigRepository;
use Haccp\Repository\DeviceRepository;
use Haccp\Support\Clock;
use PDO;
use RuntimeException;
use stdClass;
use Throwable;

final readonly class DashboardSettingsService
{
    public function __construct(
        private PDO $pdo,
        private DeviceRepository $devices,
        private DeviceConfigRepository $configs,
        private DashboardRepository $dashboard,
        private DeviceStatusService $status,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function update(string $deviceUid, stdClass $payload): array
    {
        $settings = $this->validate($payload);
        $device = $this->devices->findByUid($deviceUid);
        if ($device === null) {
            throw new ApiException(404, 'DASHBOARD_DEVICE_NOT_FOUND', 'Dashboard device was not found');
        }

        $this->pdo->beginTransaction();
        try {
            $current = $this->configs->latestForUpdate($device->id);
            if ($current === null) {
                throw new RuntimeException('Device configuration is missing.');
            }
            if ((int) $current['config_version'] !== $settings['expected_config_version']) {
                throw new ApiException(
                    409,
                    'DEVICE_CONFIG_VERSION_CONFLICT',
                    'Device configuration was changed by another request',
                    [
                        'expected_config_version' => $settings['expected_config_version'],
                        'current_config_version' => (int) $current['config_version'],
                    ],
                );
            }

            $version = $this->configs->createNext($device->id, $current, $settings, $this->clock->database($this->clock->now()));
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $states = array_map(
            fn (array $measurement): string => $this->status->alarm(
                $settings['alarm_enabled'],
                $settings['temperature_min_c'],
                $settings['temperature_max_c'],
                $measurement['temperature_c'] === null ? null : (float) $measurement['temperature_c'],
            ),
            $this->dashboard->latestMeasurementsForDevice($device->id),
        );

        return [
            'success' => true,
            'config_version' => $version,
            'settings' => $this->normalizedSettings($version, $settings),
            'alarm_status' => $this->status->worstAlarm($states),
        ];
    }

    /** @return array<string, mixed> */
    private function validate(stdClass $payload): array
    {
        $fields = [];
        foreach (array_diff(array_keys(get_object_vars($payload)), ['expected_config_version', 'alarm', 'battery']) as $field) {
            $fields[$field] = 'Unknown settings field.';
        }
        $expectedVersion = $payload->expected_config_version ?? null;
        $alarm = $payload->alarm ?? null;
        $battery = $payload->battery ?? null;

        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            $fields['expected_config_version'] = 'Must be a positive integer.';
        }
        if (!$alarm instanceof stdClass) {
            $fields['alarm'] = 'Must be an object.';
        } else {
            foreach (array_diff(array_keys(get_object_vars($alarm)), ['enabled', 'temperature_min_c', 'temperature_max_c']) as $field) {
                $fields['alarm.' . $field] = 'Unknown alarm field.';
            }
        }
        if (!$battery instanceof stdClass) {
            $fields['battery'] = 'Must be an object.';
        } else {
            foreach (array_diff(array_keys(get_object_vars($battery)), ['low_threshold_mv', 'full_threshold_mv']) as $field) {
                $fields['battery.' . $field] = 'Unknown battery field.';
            }
        }

        $enabled = $alarm instanceof stdClass ? ($alarm->enabled ?? null) : null;
        $minimum = $alarm instanceof stdClass ? ($alarm->temperature_min_c ?? null) : null;
        $maximum = $alarm instanceof stdClass ? ($alarm->temperature_max_c ?? null) : null;
        $low = $battery instanceof stdClass ? ($battery->low_threshold_mv ?? null) : null;
        $full = $battery instanceof stdClass ? ($battery->full_threshold_mv ?? null) : null;

        if (!is_bool($enabled)) {
            $fields['alarm.enabled'] = 'Must be a boolean.';
        }
        if ($minimum !== null && (!is_int($minimum) && !is_float($minimum) || !is_finite((float) $minimum) || $minimum < -100 || $minimum > 150)) {
            $fields['alarm.temperature_min_c'] = 'Must be null or a number from -100 through 150.';
        }
        if ($maximum !== null && (!is_int($maximum) && !is_float($maximum) || !is_finite((float) $maximum) || $maximum < -100 || $maximum > 150)) {
            $fields['alarm.temperature_max_c'] = 'Must be null or a number from -100 through 150.';
        }
        if ($enabled === true && ($minimum === null || $maximum === null)) {
            $fields['alarm.temperature_range'] = 'Both temperature limits are required while the alarm is enabled.';
        }
        if ((is_int($minimum) || is_float($minimum)) && (is_int($maximum) || is_float($maximum)) && $minimum >= $maximum) {
            $fields['alarm.temperature_range'] = 'Minimum must be lower than maximum.';
        }
        if (!is_int($low) || $low < 0 || $low > 10000) {
            $fields['battery.low_threshold_mv'] = 'Must be an integer from 0 through 10000.';
        }
        if (!is_int($full) || $full < 0 || $full > 10000) {
            $fields['battery.full_threshold_mv'] = 'Must be an integer from 0 through 10000.';
        }
        if (is_int($low) && is_int($full) && $low >= $full) {
            $fields['battery.thresholds'] = 'Low threshold must be lower than full threshold.';
        }

        if ($fields !== []) {
            throw new ApiException(422, 'INVALID_DEVICE_SETTINGS', 'Device settings are invalid', ['fields' => $fields]);
        }

        return [
            'expected_config_version' => $expectedVersion,
            'alarm_enabled' => $enabled,
            'temperature_min_c' => $minimum === null ? null : round((float) $minimum, 3),
            'temperature_max_c' => $maximum === null ? null : round((float) $maximum, 3),
            'battery_low_mv' => $low,
            'battery_full_mv' => $full,
        ];
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function normalizedSettings(int $version, array $settings): array
    {
        return [
            'config_version' => $version,
            'alarm' => [
                'enabled' => $settings['alarm_enabled'],
                'temperature_min_c' => $settings['temperature_min_c'],
                'temperature_max_c' => $settings['temperature_max_c'],
            ],
            'battery' => [
                'low_threshold_mv' => $settings['battery_low_mv'],
                'full_threshold_mv' => $settings['battery_full_mv'],
            ],
        ];
    }
}
