<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Domain\Device;
use Haccp\Repository\DeviceConfigRepository;
use Haccp\Support\Clock;
use RuntimeException;

final readonly class DeviceConfigService
{
    public function __construct(private DeviceConfigRepository $configs, private Clock $clock)
    {
    }

    /** @return array<string, mixed> */
    public function get(Device $device): array
    {
        $config = $this->configs->latest($device->id);
        if ($config === null) {
            throw new RuntimeException('Device configuration is missing.');
        }

        return [
            'protocol_version' => 1,
            'config_version' => (int) $config['config_version'],
            'server_time' => $this->clock->api($this->clock->now()),
            'measurement' => [
                'interval_seconds' => (int) $config['measurement_interval_seconds'],
            ],
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
}
