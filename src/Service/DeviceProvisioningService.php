<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Api\ApiException;
use Haccp\Repository\DeviceConfigRepository;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Support\Clock;
use PDO;
use PDOException;
use stdClass;
use Throwable;

final readonly class DeviceProvisioningService
{
    public function __construct(
        private PDO $pdo,
        private DeviceRepository $devices,
        private MeasurementPointRepository $measurementPoints,
        private DeviceConfigRepository $configs,
        private ApiKeyService $keys,
        private Clock $clock,
        private string $publicApiBaseUrl,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(stdClass $payload): array
    {
        $values = $this->validate($payload);
        $deviceUid = $values['device_uid'] ?? $this->generateUid();
        if ($this->devices->findByUid($deviceUid) !== null) {
            throw new ApiException(409, 'DEVICE_UID_ALREADY_EXISTS', 'Device UID is already in use');
        }

        $deviceKey = $this->keys->generate();
        $now = $this->clock->database($this->clock->now());
        $this->pdo->beginTransaction();
        try {
            $deviceId = $this->devices->create($deviceUid, $values['name'], $this->keys->hash($deviceKey), $now);
            $this->configs->createInitial($deviceId, $values['settings'], $now);
            $this->measurementPoints->create([
                'device_id' => $deviceId,
                'code' => $values['measurement_point']['code'],
                'name' => $values['measurement_point']['name'],
                'sensor_type' => $values['measurement_point']['sensor_type'],
                'location' => $values['measurement_point']['location'],
                'temperature_min_c' => null,
                'temperature_max_c' => null,
                'humidity_min_rh' => null,
                'humidity_max_rh' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw new ApiException(409, 'DEVICE_UID_ALREADY_EXISTS', 'Device UID is already in use');
            }
            throw $exception;
        }

        return [
            'success' => true,
            'device' => [
                'device_uid' => $deviceUid,
                'name' => $values['name'],
                'status' => 'active',
                'config_version' => 1,
            ],
            'setup_package' => [
                'api_base_url' => $this->publicApiBaseUrl,
                'device_uid' => $deviceUid,
                'device_key' => $deviceKey,
                'device_label' => $values['name'],
                'measurement_point' => $values['measurement_point']['code'],
            ],
            'credential_notice' => 'The device key is shown once and cannot be recovered.',
        ];
    }

    /** @return array<string, mixed> */
    private function validate(stdClass $payload): array
    {
        $fields = [];
        $allowed = ['device_uid', 'name', 'measurement_point', 'alarm', 'battery'];
        foreach (array_diff(array_keys(get_object_vars($payload)), $allowed) as $field) {
            $fields[$field] = 'Unknown provisioning field.';
        }

        $uid = $payload->device_uid ?? null;
        $name = $payload->name ?? null;
        $point = $payload->measurement_point ?? null;
        $alarm = $payload->alarm ?? null;
        $battery = $payload->battery ?? null;

        if ($uid !== null && (!is_string($uid) || preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $uid) !== 1)) {
            $fields['device_uid'] = 'Must contain 3 to 64 lowercase letters, digits, or hyphens.';
        }
        if (!is_string($name) || $this->length(trim($name)) < 1 || $this->length(trim($name)) > 160) {
            $fields['name'] = 'Must contain 1 to 160 characters.';
        }
        if (!$point instanceof stdClass) {
            $fields['measurement_point'] = 'Must be an object.';
        } else {
            foreach (array_diff(array_keys(get_object_vars($point)), ['code', 'name', 'sensor_type', 'location']) as $field) {
                $fields['measurement_point.' . $field] = 'Unknown measurement point field.';
            }
            $this->validateString($fields, 'measurement_point.code', $point->code ?? null, 1, 64, '/^[a-z0-9][a-z0-9-]{0,63}$/');
            $this->validateString($fields, 'measurement_point.name', $point->name ?? null, 1, 160);
            $this->validateString($fields, 'measurement_point.sensor_type', $point->sensor_type ?? null, 1, 64);
            $location = $point->location ?? null;
            if ($location !== null && (!is_string($location) || $this->length(trim($location)) > 255)) {
                $fields['measurement_point.location'] = 'Must be null or contain at most 255 characters.';
            }
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
        foreach (['alarm.temperature_min_c' => $minimum, 'alarm.temperature_max_c' => $maximum] as $field => $value) {
            if ($value !== null && ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < -100 || $value > 150)) {
                $fields[$field] = 'Must be null or a number from -100 through 150.';
            }
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
            throw new ApiException(422, 'INVALID_DEVICE_PROVISIONING', 'Device provisioning data is invalid', ['fields' => $fields]);
        }

        return [
            'device_uid' => $uid,
            'name' => trim($name),
            'measurement_point' => [
                'code' => trim($point->code),
                'name' => trim($point->name),
                'sensor_type' => trim($point->sensor_type),
                'location' => $location === null ? null : trim($location),
            ],
            'settings' => [
                'alarm_enabled' => $enabled,
                'temperature_min_c' => $minimum === null ? null : round((float) $minimum, 3),
                'temperature_max_c' => $maximum === null ? null : round((float) $maximum, 3),
                'battery_low_mv' => $low,
                'battery_full_mv' => $full,
            ],
        ];
    }

    /** @param array<string, string> $fields */
    private function validateString(array &$fields, string $field, mixed $value, int $minimum, int $maximum, ?string $pattern = null): void
    {
        $length = is_string($value) ? $this->length(trim($value)) : 0;
        if (!is_string($value) || $length < $minimum || $length > $maximum || ($pattern !== null && preg_match($pattern, $value) !== 1)) {
            $fields[$field] = sprintf('Must contain %d to %d valid characters.', $minimum, $maximum);
        }
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function generateUid(): string
    {
        do {
            $uid = 'haccp-' . bin2hex(random_bytes(6));
        } while ($this->devices->findByUid($uid) !== null);

        return $uid;
    }
}
