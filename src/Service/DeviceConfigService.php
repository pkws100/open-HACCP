<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Domain\Device;
use Haccp\Repository\DeviceConfigRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Support\Clock;
use JsonException;
use RuntimeException;

final readonly class DeviceConfigService
{
    public function __construct(
        private DeviceConfigRepository $configs,
        private MeasurementPointRepository $measurementPoints,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(Device $device): array
    {
        $config = $this->configs->latest($device->id);
        if ($config === null) {
            throw new RuntimeException('Device configuration is missing.');
        }

        $pointIntervals = $this->pointIntervals($config);
        $defaultInterval = (int) $config['measurement_interval_seconds'];
        $points = array_map(
            static fn (array $point): array => [
                'code' => (string) $point['code'],
                'interval_seconds' => $pointIntervals[(string) $point['code']] ?? $defaultInterval,
            ],
            $this->measurementPoints->activeForDevice($device->id),
        );

        return [
            'protocol_version' => 1,
            'config_version' => (int) $config['config_version'],
            'server_time' => $this->clock->api($this->clock->now()),
            'measurement' => [
                'interval_seconds' => $defaultInterval,
            ],
            'measurement_points' => $points,
            'upload' => [
                'interval_seconds' => (int) $config['upload_interval_seconds'],
                'max_batch_size' => (int) $config['max_batch_size'],
            ],
            'alarm' => [
                'enabled' => (bool) $config['alarm_enabled'],
                'temperature_min_c' => $config['temperature_min_c'] === null ? null : (float) $config['temperature_min_c'],
                'temperature_max_c' => $config['temperature_max_c'] === null ? null : (float) $config['temperature_max_c'],
            ],
        ];
    }

    /** @param array<string, mixed> $config @return array<string, int> */
    private function pointIntervals(array $config): array
    {
        if ($config['config_json'] === null || $config['config_json'] === '') {
            return [];
        }

        try {
            $decoded = json_decode((string) $config['config_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Device configuration extension is invalid.', 0, $exception);
        }
        $intervals = $decoded['measurement_point_intervals'] ?? [];
        if (!is_array($intervals)) {
            throw new RuntimeException('Device measurement point intervals are invalid.');
        }

        $normalized = [];
        foreach ($intervals as $code => $interval) {
            if (!is_string($code) || !is_int($interval) || $interval < 30 || $interval > 86400) {
                throw new RuntimeException('Device measurement point interval is invalid.');
            }
            $normalized[$code] = $interval;
        }

        return $normalized;
    }
}
