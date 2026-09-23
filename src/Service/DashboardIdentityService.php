<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Api\ApiException;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Support\Clock;
use PDO;
use stdClass;
use Throwable;

final readonly class DashboardIdentityService
{
    public function __construct(
        private PDO $pdo,
        private DeviceRepository $devices,
        private MeasurementPointRepository $points,
        private AuditService $audit,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function update(string $deviceUid, stdClass $payload, int $userId): array
    {
        $values = $this->validate($payload);
        $this->pdo->beginTransaction();
        try {
            $device = $this->devices->findByUidForUpdate($deviceUid);
            if ($device === null) {
                throw new ApiException(404, 'DASHBOARD_DEVICE_NOT_FOUND', 'Dashboard device was not found');
            }

            $point = null;
            if ($values['point'] !== null) {
                $point = $this->points->findActiveByDeviceAndCode($device->id, $values['point']['code']);
                if ($point === null) {
                    throw new ApiException(404, 'MEASUREMENT_POINT_NOT_FOUND', 'Active measurement point was not found for this device');
                }
            }

            $deviceName = $values['name'] ?? $device->name;
            $pointName = $point === null ? null : ($values['point']['name'] ?? (string) $point['name']);
            $pointLocation = $point === null ? null : (
                array_key_exists('location', $values['point']) ? $values['point']['location'] : $point['location']
            );
            $changes = [];
            $now = $this->clock->database($this->clock->now());
            if ($deviceName !== $device->name) {
                $this->devices->updateDisplayName($device->id, $deviceName, $now);
                $changes['name'] = ['before' => $device->name, 'after' => $deviceName];
            }
            if ($point !== null && ($pointName !== (string) $point['name'] || $pointLocation !== $point['location'])) {
                $this->points->updateDisplay((int) $point['id'], $device->id, $pointName, $pointLocation, $now);
                if ($pointName !== (string) $point['name']) {
                    $changes['measurement_point.name'] = ['before' => $point['name'], 'after' => $pointName];
                }
                if ($pointLocation !== $point['location']) {
                    $changes['measurement_point.location'] = ['before' => $point['location'], 'after' => $pointLocation];
                }
            }
            if ($changes !== []) {
                $this->audit->append('device.identity_updated', $userId, 'device', $deviceUid, [
                    'measurement_point' => $point['code'] ?? null,
                    'changes' => $changes,
                ]);
            }
            $this->pdo->commit();

            return [
                'success' => true,
                'device' => ['device_uid' => $deviceUid, 'name' => $deviceName, 'status' => $device->status],
                'measurement_point' => $point === null ? null : [
                    'code' => $point['code'],
                    'name' => $pointName,
                    'location' => $pointLocation,
                ],
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{name: ?string, point: ?array<string, mixed>} */
    private function validate(stdClass $payload): array
    {
        $fields = [];
        foreach (array_diff(array_keys(get_object_vars($payload)), ['name', 'measurement_point']) as $field) {
            $fields[$field] = 'Unknown identity field.';
        }
        $hasName = property_exists($payload, 'name');
        $hasPoint = property_exists($payload, 'measurement_point');
        if (!$hasName && !$hasPoint) {
            $fields['identity'] = 'At least one display field is required.';
        }

        $name = null;
        if ($hasName) {
            if (!is_string($payload->name) || mb_strlen(trim($payload->name)) < 1 || mb_strlen(trim($payload->name)) > 160) {
                $fields['name'] = 'Must contain 1 to 160 characters.';
            } else {
                $name = trim($payload->name);
            }
        }

        $pointValues = null;
        if ($hasPoint) {
            $point = $payload->measurement_point;
            if (!$point instanceof stdClass) {
                $fields['measurement_point'] = 'Must be an object.';
            } else {
                foreach (array_diff(array_keys(get_object_vars($point)), ['code', 'name', 'location']) as $field) {
                    $fields['measurement_point.' . $field] = 'Unknown measurement point identity field.';
                }
                $code = $point->code ?? null;
                if (!is_string($code) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $code) !== 1) {
                    $fields['measurement_point.code'] = 'Must be an existing measurement point code.';
                }
                $pointHasName = property_exists($point, 'name');
                $pointHasLocation = property_exists($point, 'location');
                if (!$pointHasName && !$pointHasLocation) {
                    $fields['measurement_point'] = 'Name or location is required.';
                }
                $pointValues = ['code' => $code];
                if ($pointHasName) {
                    if (!is_string($point->name) || mb_strlen(trim($point->name)) < 1 || mb_strlen(trim($point->name)) > 160) {
                        $fields['measurement_point.name'] = 'Must contain 1 to 160 characters.';
                    } else {
                        $pointValues['name'] = trim($point->name);
                    }
                }
                if ($pointHasLocation) {
                    if ($point->location !== null && (!is_string($point->location) || mb_strlen(trim($point->location)) > 255)) {
                        $fields['measurement_point.location'] = 'Must be null or contain at most 255 characters.';
                    } else {
                        $pointValues['location'] = $point->location === null || trim($point->location) === ''
                            ? null : trim($point->location);
                    }
                }
            }
        }

        if ($fields !== []) {
            throw new ApiException(422, 'INVALID_DEVICE_IDENTITY', 'Die Gerätebezeichnung ist ungültig.', ['fields' => $fields]);
        }

        return ['name' => $name, 'point' => $pointValues];
    }
}
