<?php

declare(strict_types=1);

namespace Haccp\Service;

use DateTimeImmutable;
use DateTimeZone;
use Haccp\Repository\DashboardRepository;
use Haccp\Support\Clock;

final readonly class DashboardService
{
    private const ALLOWED_WINDOWS = [6, 24, 72, 168];

    public function __construct(
        private DashboardRepository $dashboard,
        private Clock $clock,
        private DeviceStatusService $status,
    )
    {
    }

    /** @return array<string, mixed> */
    public function overview(?string $requestedDevice, ?string $requestedPoint, int $requestedHours): array
    {
        $hours = in_array($requestedHours, self::ALLOWED_WINDOWS, true) ? $requestedHours : 24;
        $now = $this->clock->now();
        $cutoff = $this->clock->database($now->modify(sprintf('-%d hours', $hours)));
        $staleCutoff = $this->clock->database($now->modify('-12 hours'));
        $devices = $this->dashboard->devices();
        $device = $requestedDevice === null ? null : $this->dashboard->deviceByUid($requestedDevice);
        if ($device === null && $devices !== []) {
            $device = $this->dashboard->deviceByUid((string) $devices[0]['device_uid']);
        }

        $fleet = $this->dashboard->fleetSummary($cutoff, $staleCutoff);
        $base = [
            'server_time' => $this->clock->api($now),
            'window_hours' => $hours,
            'fleet' => [
                'total_devices' => (int) ($fleet['total_devices'] ?? 0),
                'active_devices' => (int) ($fleet['active_devices'] ?? 0),
                'stale_devices' => (int) ($fleet['stale_devices'] ?? 0),
                'measurements_in_window' => (int) ($fleet['measurements_in_window'] ?? 0),
            ],
            'devices' => array_map(fn (array $row): array => $this->device($row), $devices),
        ];

        if ($device === null) {
            return $base + [
                'selection' => null,
                'measurement_points' => [],
                'kpis' => null,
                'series' => [],
                'recent_measurements' => [],
                'diagnostics' => null,
                'settings' => null,
            ];
        }

        $points = $this->dashboard->measurementPoints((int) $device['id']);
        $point = null;
        foreach ($points as $candidate) {
            if ($requestedPoint !== null && $candidate['code'] === $requestedPoint) {
                $point = $candidate;
                break;
            }
        }
        $point ??= $points[0] ?? null;

        $result = $base + [
            'selection' => [
                'device_uid' => $device['device_uid'],
                'measurement_point' => $point['code'] ?? null,
            ],
            'selected_device' => $this->device($device),
            'measurement_points' => array_map(fn (array $row): array => $this->point($row), $points),
            'kpis' => null,
            'series' => [],
            'recent_measurements' => [],
            'diagnostics' => $this->transmission($this->dashboard->latestTransmission((int) $device['id'])),
            'settings' => $this->settings($device, $points),
        ];

        if ($point === null) {
            return $result;
        }

        $pointId = (int) $point['id'];
        $summary = $this->dashboard->pointSummary($pointId, $cutoff);
        $latest = $this->dashboard->latestMeasurement($pointId);
        $result['selected_measurement_point'] = $this->point($point);
        $result['kpis'] = [
            'measurement_count' => (int) $summary['measurement_count'],
            'latest_temperature_c' => $this->float($latest['temperature_c'] ?? null),
            'latest_humidity_rh' => $this->float($latest['humidity_rh'] ?? null),
            'latest_battery_mv' => isset($latest['battery_mv']) ? (int) $latest['battery_mv'] : null,
            'latest_measured_at' => $this->timestamp($latest['measured_at'] ?? null),
            'average_temperature_c' => $this->float($summary['average_temperature_c']),
            'minimum_temperature_c' => $this->float($summary['minimum_temperature_c']),
            'maximum_temperature_c' => $this->float($summary['maximum_temperature_c']),
            'average_humidity_rh' => $this->float($summary['average_humidity_rh']),
            'alarm_status' => $this->status->alarm(
                (bool) $device['alarm_enabled'],
                $this->float($device['temperature_min_c']),
                $this->float($device['temperature_max_c']),
                $this->float($latest['temperature_c'] ?? null),
            ),
        ];
        $result['series'] = array_map(fn (array $row): array => $this->measurement($row), $this->dashboard->series($pointId, $cutoff));
        $result['recent_measurements'] = array_map(
            fn (array $row): array => $this->measurement($row, true),
            $this->dashboard->recentMeasurements($pointId),
        );

        return $result;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function device(array $row): array
    {
        $batteryMv = isset($row['last_battery_mv']) ? (int) $row['last_battery_mv'] : null;
        $rssiDbm = isset($row['last_rssi_dbm']) ? (int) $row['last_rssi_dbm'] : null;
        $minimum = $this->float($row['temperature_min_c'] ?? null);
        $maximum = $this->float($row['temperature_max_c'] ?? null);
        $enabled = (bool) ($row['alarm_enabled'] ?? false);
        $latestMeasurements = $this->dashboard->latestMeasurementsForDevice((int) $row['id']);
        $alarmStates = array_map(
            fn (array $measurement): string => $this->status->alarm(
                $enabled,
                $minimum,
                $maximum,
                $this->float($measurement['temperature_c']),
            ),
            $latestMeasurements,
        );
        $latestMeasurement = null;
        foreach ($latestMeasurements as $measurement) {
            if ($measurement['temperature_c'] === null) {
                continue;
            }
            if ($latestMeasurement === null || (string) $measurement['measured_at'] > (string) $latestMeasurement['measured_at']) {
                $latestMeasurement = $measurement;
            }
        }

        return [
            'device_uid' => $row['device_uid'],
            'name' => $row['name'],
            'status' => $row['status'],
            'hardware_revision' => $row['hardware_revision'],
            'firmware_version' => $row['firmware_version'],
            'last_seen_at' => $this->timestamp($row['last_seen_at']),
            'last_rssi_dbm' => $rssiDbm,
            'last_battery_mv' => $batteryMv,
            'latest_temperature_c' => $this->float($latestMeasurement['temperature_c'] ?? null),
            'latest_temperature_measured_at' => $this->timestamp($latestMeasurement['measured_at'] ?? null),
            'measurement_point_count' => isset($row['measurement_point_count']) ? (int) $row['measurement_point_count'] : null,
            'battery' => [
                'millivolts' => $batteryMv,
                'state' => $this->status->battery(
                    $batteryMv,
                    (int) ($row['battery_low_mv'] ?? 5600),
                    (int) ($row['battery_full_mv'] ?? 6000),
                ),
            ],
            'wifi' => [
                'rssi_dbm' => $rssiDbm,
                'bars' => $this->status->wifiBars($rssiDbm),
            ],
            'alarm' => [
                'state' => $enabled ? $this->status->worstAlarm($alarmStates) : 'disabled',
                'enabled' => $enabled,
                'temperature_min_c' => $minimum,
                'temperature_max_c' => $maximum,
            ],
        ];
    }

    /** @param array<string, mixed> $row @param list<array<string, mixed>> $points @return array<string, mixed> */
    private function settings(array $row, array $points): array
    {
        $pointIntervals = [];
        if (is_string($row['config_json']) && $row['config_json'] !== '') {
            $decoded = json_decode($row['config_json'], true);
            if (is_array($decoded) && is_array($decoded['measurement_point_intervals'] ?? null)) {
                $pointIntervals = $decoded['measurement_point_intervals'];
            }
        }
        $defaultInterval = (int) $row['measurement_interval_seconds'];

        return [
            'config_version' => (int) $row['config_version'],
            'alarm' => [
                'enabled' => (bool) $row['alarm_enabled'],
                'temperature_min_c' => $this->float($row['temperature_min_c']),
                'temperature_max_c' => $this->float($row['temperature_max_c']),
            ],
            'battery' => [
                'low_threshold_mv' => (int) $row['battery_low_mv'],
                'full_threshold_mv' => (int) $row['battery_full_mv'],
            ],
            'schedule' => [
                'default_measurement_interval_seconds' => $defaultInterval,
                'upload_interval_seconds' => (int) $row['upload_interval_seconds'],
                'measurement_points' => array_map(
                    static fn (array $point): array => [
                        'measurement_point' => (string) $point['code'],
                        'interval_seconds' => isset($pointIntervals[(string) $point['code']])
                            ? (int) $pointIntervals[(string) $point['code']]
                            : $defaultInterval,
                    ],
                    $points,
                ),
            ],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function point(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'code' => $row['code'],
            'name' => $row['name'],
            'sensor_type' => $row['sensor_type'],
            'location' => $row['location'],
            'temperature_min_c' => $this->float($row['temperature_min_c']),
            'temperature_max_c' => $this->float($row['temperature_max_c']),
            'humidity_min_rh' => $this->float($row['humidity_min_rh']),
            'humidity_max_rh' => $this->float($row['humidity_max_rh']),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function measurement(array $row, bool $includeReceivedAt = false): array
    {
        $result = [
            'sequence' => (int) $row['sequence'],
            'measured_at' => $this->timestamp($row['measured_at']),
            'temperature_c' => $this->float($row['temperature_c']),
            'humidity_rh' => $this->float($row['humidity_rh']),
            'battery_mv' => (int) $row['battery_mv'],
        ];
        if ($includeReceivedAt) {
            $result['received_at'] = $this->timestamp($row['received_at']);
        }

        return $result;
    }

    /** @param array<string, mixed>|null $row @return array<string, mixed>|null */
    private function transmission(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'type' => $row['transmission_type'],
            'batch_id' => $row['batch_id'],
            'received_at' => $this->timestamp($row['received_at']),
            'firmware_version' => $row['firmware_version'],
            'hardware_revision' => $row['hardware_revision'],
            'battery_mv' => (int) $row['battery_mv'],
            'rssi_dbm' => (int) $row['rssi_dbm'],
            'wifi_connect_ms' => (int) $row['wifi_connect_ms'],
            'boot_count' => (int) $row['boot_count'],
            'measurement_count' => (int) $row['measurement_count'],
            'accepted_count' => (int) $row['accepted_count'],
            'duplicate_count' => (int) $row['duplicate_count'],
            'rejected_count' => (int) $row['rejected_count'],
        ];
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 3);
    }

    private function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
