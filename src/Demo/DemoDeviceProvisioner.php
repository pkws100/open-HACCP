<?php

declare(strict_types=1);

namespace Haccp\Demo;

use Haccp\Repository\DeviceConfigRepository;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Service\ApiKeyService;
use Haccp\Support\Clock;
use PDO;
use RuntimeException;
use Throwable;

final readonly class DemoDeviceProvisioner
{
    public function __construct(
        private PDO $pdo,
        private DeviceRepository $devices,
        private MeasurementPointRepository $points,
        private DeviceConfigRepository $configs,
        private ApiKeyService $keys,
        private Clock $clock,
    ) {
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function provision(array $state): array
    {
        $state['schema_version'] = 1;
        $state['devices'] = is_array($state['devices'] ?? null) ? $state['devices'] : [];

        foreach (DemoProfileCatalog::all() as $profile) {
            $uid = (string) $profile['device_uid'];
            if (!str_starts_with($uid, 'haccp-demo-')) {
                throw new RuntimeException('Automatic provisioning is restricted to haccp-demo-* devices.');
            }

            $storedState = is_array($state['devices'][$uid] ?? null) ? $state['devices'][$uid] : [];
            $firstProvisioning = $storedState === [];
            $key = $storedState['key'] ?? null;
            if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
                $key = $this->keys->generate();
            }
            $now = $this->clock->database($this->clock->now());

            $this->pdo->beginTransaction();
            try {
                $device = $this->devices->findByUid($uid);
                $created = $device === null;
                if ($created) {
                    $deviceId = $this->devices->create($uid, (string) $profile['name'], $this->keys->hash($key), $now);
                    $this->configs->createDefault($deviceId, $now);
                } else {
                    $deviceId = $device->id;
                    $this->devices->activateAndRename($deviceId, (string) $profile['name'], $now);
                    if (!$this->keys->verify($key, $device->apiKeyHash)) {
                        $this->devices->updateApiKey($deviceId, $this->keys->hash($key), $now);
                    }
                }

                $point = $this->points->findByDeviceAndCode($deviceId, (string) $profile['measurement_point']);
                $pointValues = [
                    'device_id' => $deviceId,
                    'code' => $profile['measurement_point'],
                    'name' => $profile['measurement_point_name'],
                    'sensor_type' => 'SHT45 simulator',
                    'location' => $profile['location'],
                    'temperature_min_c' => null,
                    'temperature_max_c' => null,
                    'humidity_min_rh' => null,
                    'humidity_max_rh' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($point === null) {
                    $this->points->create($pointValues);
                } else {
                    $this->points->updateDemo((int) $point['id'], $pointValues);
                }

                $config = $this->configs->latestForUpdate($deviceId);
                if ($config === null) {
                    $this->configs->createDefault($deviceId, $now);
                    $config = $this->configs->latestForUpdate($deviceId);
                }
                if ($config === null) {
                    throw new RuntimeException('Demo device configuration is missing.');
                }
                if ($created || ($firstProvisioning && (int) $config['config_version'] === 1 && !(bool) $config['alarm_enabled'])) {
                    $this->configs->createNext($deviceId, $config, [
                        'alarm_enabled' => true,
                        'temperature_min_c' => $profile['temperature_min_c'],
                        'temperature_max_c' => $profile['temperature_max_c'],
                        'battery_low_mv' => 5600,
                        'battery_full_mv' => 6000,
                    ], $now);
                }

                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }

            $state['devices'][$uid] = array_replace([
                'sequence' => 0,
                'boot_count' => 0,
                'upload_count' => 0,
                'history_seeded' => false,
                'pending_batch' => null,
            ], $storedState, ['key' => $key]);
        }

        return $state;
    }
}
