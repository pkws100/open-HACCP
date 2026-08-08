<?php

declare(strict_types=1);

namespace Haccp\Demo;

use DateTimeImmutable;
use DateTimeZone;

final readonly class DemoFleetRunner
{
    public function __construct(
        private DemoStateStore $store,
        private DemoDeviceProvisioner $provisioner,
        private DemoPayloadFactory $payloads,
        private DemoHttpClient $http,
    ) {
    }

    /** @return array{state: array<string, mixed>, results: list<array<string, mixed>>, success: bool} */
    public function cycle(): array
    {
        $state = $this->provisioner->provision($this->store->load());
        foreach (DemoProfileCatalog::all() as $profile) {
            $uid = (string) $profile['device_uid'];
            $state['devices'][$uid]['boot_count'] = (int) $state['devices'][$uid]['boot_count'] + 1;
        }
        $this->store->save($state);

        $results = [];
        $success = true;
        foreach (DemoProfileCatalog::all() as $profile) {
            $uid = (string) $profile['device_uid'];
            $deviceState = &$state['devices'][$uid];
            $key = (string) $deviceState['key'];
            $heartbeat = $this->http->post(
                '/api/v1/device/heartbeat',
                $uid,
                $key,
                $this->payloads->heartbeat($profile, (int) $deviceState['boot_count']),
            );

            $pending = is_array($deviceState['pending_batch'] ?? null) ? $deviceState['pending_batch'] : null;
            if ($pending === null) {
                $deviceState['upload_count'] = (int) $deviceState['upload_count'] + 1;
                $count = (bool) $deviceState['history_seeded'] ? 1 : 12;
                $pending = $this->payloads->batch(
                    $profile,
                    (int) $deviceState['sequence'] + 1,
                    $count,
                    (int) $deviceState['boot_count'],
                    (int) $deviceState['upload_count'],
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                );
                $deviceState['pending_batch'] = $pending;
                $this->store->save($state);
            }

            $batch = $this->http->post('/api/v1/device/measurements', $uid, $key, $pending);
            $acknowledged = $batch['status'] === 200
                && is_array($batch['json'])
                && $this->payloads->isAcknowledged($pending, $batch['json']);
            if ($acknowledged) {
                $last = end($pending['measurements']);
                $deviceState['sequence'] = (int) $last['sequence'];
                $deviceState['history_seeded'] = true;
                $deviceState['pending_batch'] = null;
                $this->store->save($state);
            }

            $deviceSuccess = $heartbeat['status'] === 200 && $acknowledged;
            $success = $success && $deviceSuccess;
            $results[] = [
                'device_uid' => $uid,
                'heartbeat_status' => $heartbeat['status'],
                'batch_status' => $batch['status'],
                'measurement_count' => count($pending['measurements']),
                'acknowledged' => $acknowledged,
            ];
            unset($deviceState);
        }

        return ['state' => $state, 'results' => $results, 'success' => $success];
    }
}
