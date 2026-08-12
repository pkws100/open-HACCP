<?php

declare(strict_types=1);

namespace Haccp\Service;

use DateTimeImmutable;
use DateTimeZone;
use Haccp\Api\ApiException;
use Haccp\Support\Clock;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;

final class ProtocolValidator
{
    private stdClass $schema;
    private Validator $validator;
    private DateTimeZone $utc;

    public function __construct(private readonly Clock $clock, string $schemaPath)
    {
        $contents = file_get_contents($schemaPath);
        if ($contents === false) {
            throw new RuntimeException('Protocol schema could not be read.');
        }

        $schema = json_decode($contents);
        if (!$schema instanceof stdClass) {
            throw new RuntimeException('Protocol schema is invalid.');
        }

        $this->schema = $schema;
        $this->validator = new Validator();
        $this->utc = new DateTimeZone('UTC');
    }

    /** @return array<string, mixed> */
    public function validateBatchEnvelope(stdClass $payload): array
    {
        if (!property_exists($payload, 'protocol_version') || $payload->protocol_version !== 1) {
            throw new ApiException(422, 'UNSUPPORTED_PROTOCOL_VERSION', 'protocol_version must be 1');
        }

        foreach (['batch_id', 'firmware_version', 'hardware_revision', 'sent_at', 'diagnostics', 'measurements'] as $field) {
            if (!property_exists($payload, $field)) {
                throw new ApiException(422, 'MISSING_REQUIRED_FIELD', sprintf('%s is required', $field), ['field' => $field]);
            }
        }

        if (!is_string($payload->batch_id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $payload->batch_id) !== 1) {
            throw new ApiException(422, 'INVALID_BATCH_ID', 'batch_id has an invalid format');
        }
        if (!$this->validVersion($payload->firmware_version)) {
            throw new ApiException(422, 'INVALID_FIRMWARE_VERSION', 'firmware_version has an invalid format');
        }
        if (!$this->validVersion($payload->hardware_revision)) {
            throw new ApiException(422, 'INVALID_HARDWARE_REVISION', 'hardware_revision has an invalid format');
        }

        $sentAt = $this->parseTimestamp($payload->sent_at);
        if ($sentAt === null || $sentAt > $this->clock->now()->modify('+24 hours')) {
            throw new ApiException(422, 'INVALID_SENT_AT', 'sent_at must be UTC and no more than 24 hours in the future');
        }

        if (!$payload->diagnostics instanceof stdClass) {
            throw new ApiException(422, 'INVALID_DIAGNOSTICS', 'diagnostics must be an object');
        }
        $diagnostics = $this->validateDiagnostics($payload->diagnostics);
        $deviceInfo = $this->validateDeviceInfo($payload->device_info ?? null);
        $operationalStatus = $this->validateOperationalStatus($payload->operational_status ?? null);
        $configAcknowledgement = $this->validateConfigAcknowledgement($payload->config_ack ?? null);

        if (!is_array($payload->measurements) || count($payload->measurements) < 1) {
            throw new ApiException(422, 'EMPTY_BATCH', 'measurements must contain at least one item');
        }
        if (count($payload->measurements) > 500) {
            throw new ApiException(422, 'BATCH_SIZE_EXCEEDED', 'measurements must not contain more than 500 items');
        }
        foreach ($payload->measurements as $measurement) {
            if (!$measurement instanceof stdClass) {
                throw new ApiException(422, 'INVALID_BATCH', 'Every measurements item must be an object');
            }
        }

        if (!$this->validateDefinition($payload, 'batchEnvelope')) {
            throw new ApiException(422, 'INVALID_BATCH', 'Batch envelope does not match Sensor Protocol V1');
        }

        return [
            'batch_id' => $payload->batch_id,
            'firmware_version' => $payload->firmware_version,
            'hardware_revision' => $payload->hardware_revision,
            'sent_at' => $sentAt,
            'diagnostics' => $diagnostics,
            'device_info' => $deviceInfo,
            'operational_status' => $operationalStatus,
            'config_ack' => $configAcknowledgement,
            'measurements' => $payload->measurements,
        ];
    }

    /** @return array{valid: bool, value?: array<string, mixed>, code?: string, message?: string} */
    public function validateMeasurement(stdClass $measurement, DateTimeImmutable $sentAt): array
    {
        $allowed = ['measurement_point', 'sequence', 'measured_at', 'temperature_c', 'humidity_rh', 'battery_mv'];
        foreach (array_keys(get_object_vars($measurement)) as $field) {
            if (!in_array($field, $allowed, true)) {
                return $this->invalid('UNKNOWN_MEASUREMENT_FIELD', sprintf('Unknown measurement field: %s', $field));
            }
        }
        foreach ($allowed as $field) {
            if (!property_exists($measurement, $field)) {
                return $this->invalid('MISSING_MEASUREMENT_FIELD', sprintf('%s is required', $field));
            }
        }

        if (!is_string($measurement->measurement_point)
            || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $measurement->measurement_point) !== 1) {
            return $this->invalid('INVALID_MEASUREMENT_POINT', 'measurement_point has an invalid format');
        }
        if (!is_int($measurement->sequence) || $measurement->sequence < 1) {
            return $this->invalid('INVALID_SEQUENCE', 'sequence must be a positive integer');
        }

        $measuredAt = $this->parseTimestamp($measurement->measured_at);
        if ($measuredAt === null || $measuredAt > $sentAt || $measuredAt > $this->clock->now()->modify('+24 hours')) {
            return $this->invalid('INVALID_MEASURED_AT', 'measured_at must be UTC, not after sent_at, and not over 24 hours in the future');
        }
        if (!is_int($measurement->temperature_c) && !is_float($measurement->temperature_c)) {
            return $this->invalid('INVALID_TEMPERATURE', 'temperature_c must be a number between -100 and 150');
        }
        if ($measurement->temperature_c < -100 || $measurement->temperature_c > 150) {
            return $this->invalid('INVALID_TEMPERATURE', 'temperature_c must be between -100 and 150');
        }
        if (!is_int($measurement->humidity_rh) && !is_float($measurement->humidity_rh)) {
            return $this->invalid('INVALID_HUMIDITY', 'humidity_rh must be a number between 0 and 100');
        }
        if ($measurement->humidity_rh < 0 || $measurement->humidity_rh > 100) {
            return $this->invalid('INVALID_HUMIDITY', 'humidity_rh must be between 0 and 100');
        }
        if (!is_int($measurement->battery_mv) || $measurement->battery_mv < 0 || $measurement->battery_mv > 10000) {
            return $this->invalid('INVALID_BATTERY', 'battery_mv must be an integer between 0 and 10000');
        }
        if (!$this->validateDefinition($measurement, 'measurement')) {
            return $this->invalid('INVALID_MEASUREMENT', 'Measurement does not match Sensor Protocol V1');
        }

        return [
            'valid' => true,
            'value' => [
                'measurement_point' => $measurement->measurement_point,
                'sequence' => $measurement->sequence,
                'measured_at' => $measuredAt,
                'measured_at_db' => $this->clock->database($measuredAt),
                'temperature_c' => (float) $measurement->temperature_c,
                'temperature_c_db' => number_format((float) $measurement->temperature_c, 3, '.', ''),
                'humidity_rh' => (float) $measurement->humidity_rh,
                'humidity_rh_db' => number_format((float) $measurement->humidity_rh, 3, '.', ''),
                'battery_mv' => $measurement->battery_mv,
            ],
        ];
    }

    /** @return array<string, int|string> */
    public function validateHeartbeat(stdClass $payload): array
    {
        if (!property_exists($payload, 'protocol_version') || $payload->protocol_version !== 1) {
            throw new ApiException(422, 'UNSUPPORTED_PROTOCOL_VERSION', 'protocol_version must be 1');
        }
        foreach (['firmware_version', 'hardware_revision', 'battery_mv', 'rssi_dbm', 'wifi_connect_ms', 'boot_count'] as $field) {
            if (!property_exists($payload, $field)) {
                throw new ApiException(422, 'MISSING_REQUIRED_FIELD', sprintf('%s is required', $field), ['field' => $field]);
            }
        }
        if (!$this->validVersion($payload->firmware_version)) {
            throw new ApiException(422, 'INVALID_FIRMWARE_VERSION', 'firmware_version has an invalid format');
        }
        if (!$this->validVersion($payload->hardware_revision)) {
            throw new ApiException(422, 'INVALID_HARDWARE_REVISION', 'hardware_revision has an invalid format');
        }

        $telemetryObject = (object) [
            'battery_mv' => $payload->battery_mv,
            'rssi_dbm' => $payload->rssi_dbm,
            'wifi_connect_ms' => $payload->wifi_connect_ms,
            'boot_count' => $payload->boot_count,
            'errors' => $payload->errors ?? [],
        ];
        $telemetry = $this->validateDiagnostics($telemetryObject);
        $deviceInfo = $this->validateDeviceInfo($payload->device_info ?? null);
        $operationalStatus = $this->validateOperationalStatus($payload->operational_status ?? null);
        $configAcknowledgement = $this->validateConfigAcknowledgement($payload->config_ack ?? null);

        if (!$this->validateDefinition($payload, 'heartbeat')) {
            throw new ApiException(422, 'INVALID_HEARTBEAT', 'Heartbeat does not match Sensor Protocol V1');
        }

        return [
            'firmware_version' => $payload->firmware_version,
            'hardware_revision' => $payload->hardware_revision,
            ...$telemetry,
            'device_info' => $deviceInfo,
            'operational_status' => $operationalStatus,
            'config_ack' => $configAcknowledgement,
        ];
    }

    /** @return array<string, mixed>|null */
    private function validateDeviceInfo(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof stdClass) {
            throw new ApiException(422, 'INVALID_DEVICE_INFO', 'device_info must be an object');
        }
        $required = [
            'board_model', 'chip_model', 'chip_revision', 'cpu_cores', 'flash_bytes', 'psram_bytes',
            'heap_free_bytes', 'sensor_model', 'sensor_status', 'queue_capacity', 'capabilities',
        ];
        foreach ($required as $field) {
            if (!property_exists($value, $field)) {
                throw new ApiException(422, 'INVALID_DEVICE_INFO', sprintf('%s is required in device_info', $field));
            }
        }
        foreach (['board_model', 'chip_model', 'sensor_model'] as $field) {
            if (!is_string($value->{$field})
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+ -]{0,63}$/', $value->{$field}) !== 1) {
                throw new ApiException(422, 'INVALID_DEVICE_INFO', sprintf('%s has an invalid format', $field));
            }
        }
        if (!is_int($value->chip_revision) || $value->chip_revision < 0 || $value->chip_revision > 255
            || !is_int($value->cpu_cores) || $value->cpu_cores < 1 || $value->cpu_cores > 8) {
            throw new ApiException(422, 'INVALID_DEVICE_INFO', 'chip_revision or cpu_cores is invalid');
        }
        foreach (['flash_bytes', 'psram_bytes', 'heap_free_bytes'] as $field) {
            if (!is_int($value->{$field}) || $value->{$field} < 0 || $value->{$field} > 17179869184) {
                throw new ApiException(422, 'INVALID_DEVICE_INFO', sprintf('%s is outside the supported range', $field));
            }
        }
        if (!is_string($value->sensor_status) || !in_array($value->sensor_status, ['ready', 'unavailable', 'error'], true)
            || !is_int($value->queue_capacity) || $value->queue_capacity < 1 || $value->queue_capacity > 5000) {
            throw new ApiException(422, 'INVALID_DEVICE_INFO', 'sensor_status or queue_capacity is invalid');
        }
        if (!is_array($value->capabilities) || count($value->capabilities) > 20) {
            throw new ApiException(422, 'INVALID_DEVICE_INFO', 'capabilities must contain at most 20 codes');
        }
        $capabilities = [];
        foreach ($value->capabilities as $capability) {
            if (!is_string($capability) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $capability) !== 1) {
                throw new ApiException(422, 'INVALID_DEVICE_INFO', 'capability codes have an invalid format');
            }
            $capabilities[] = $capability;
        }
        if (count(array_unique($capabilities)) !== count($capabilities)) {
            throw new ApiException(422, 'INVALID_DEVICE_INFO', 'capability codes must be unique');
        }

        return [
            'board_model' => $value->board_model,
            'chip_model' => $value->chip_model,
            'chip_revision' => $value->chip_revision,
            'cpu_cores' => $value->cpu_cores,
            'flash_bytes' => $value->flash_bytes,
            'psram_bytes' => $value->psram_bytes,
            'heap_free_bytes' => $value->heap_free_bytes,
            'sensor_model' => $value->sensor_model,
            'sensor_status' => $value->sensor_status,
            'queue_capacity' => $value->queue_capacity,
            'capabilities' => $capabilities,
        ];
    }

    /** @return array<string, mixed>|null */
    private function validateOperationalStatus(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof stdClass) {
            throw new ApiException(422, 'INVALID_OPERATIONAL_STATUS', 'operational_status must be an object');
        }
        $integerFields = [
            'queue_depth' => 5000,
            'awake_ms' => 3600000,
            'wifi_failures_since_report' => 4294967295,
            'upload_failures_since_report' => 4294967295,
            'max_consecutive_wifi_failures' => 4294967295,
            'sleep_fallbacks_since_report' => 4294967295,
        ];
        if (!property_exists($value, 'provisioned') || !is_bool($value->provisioned)) {
            throw new ApiException(422, 'INVALID_OPERATIONAL_STATUS', 'provisioned must be a boolean');
        }
        foreach ($integerFields as $field => $maximum) {
            if (!property_exists($value, $field) || !is_int($value->{$field})
                || $value->{$field} < 0 || $value->{$field} > $maximum) {
                throw new ApiException(422, 'INVALID_OPERATIONAL_STATUS', sprintf('%s is invalid', $field));
            }
        }
        foreach (['wake_reason', 'reset_reason'] as $field) {
            if (!property_exists($value, $field) || !is_string($value->{$field})
                || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $value->{$field}) !== 1) {
                throw new ApiException(422, 'INVALID_OPERATIONAL_STATUS', sprintf('%s is invalid', $field));
            }
        }
        if (!property_exists($value, 'requested_sleep_mode') || !is_string($value->requested_sleep_mode)
            || !in_array($value->requested_sleep_mode, ['deep_sleep', 'light_sleep_fallback', 'awake_restart_fallback', 'none'], true)) {
            throw new ApiException(422, 'INVALID_OPERATIONAL_STATUS', 'requested_sleep_mode is invalid');
        }

        $normalized = [
            'provisioned' => $value->provisioned,
            'wake_reason' => $value->wake_reason,
            'reset_reason' => $value->reset_reason,
            'requested_sleep_mode' => $value->requested_sleep_mode,
        ];
        foreach (array_keys($integerFields) as $field) {
            $normalized[$field] = $value->{$field};
        }

        return $normalized;
    }

    /** @return array<string, int|string>|null */
    private function validateConfigAcknowledgement(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof stdClass || !property_exists($value, 'applied_version')
            || !is_int($value->applied_version) || $value->applied_version < 0 || $value->applied_version > 4294967295
            || !property_exists($value, 'status') || !is_string($value->status)
            || !in_array($value->status, ['default', 'applied', 'rejected'], true)) {
            throw new ApiException(422, 'INVALID_CONFIG_ACK', 'config_ack is invalid');
        }
        if ($value->status === 'applied' && $value->applied_version < 1) {
            throw new ApiException(422, 'INVALID_CONFIG_ACK', 'an applied config acknowledgement needs a positive version');
        }

        return ['applied_version' => $value->applied_version, 'status' => $value->status];
    }

    /** @return array{battery_mv: int, rssi_dbm: int, wifi_connect_ms: int, boot_count: int, errors: list<string>} */
    private function validateDiagnostics(stdClass $diagnostics): array
    {
        foreach (['battery_mv', 'rssi_dbm', 'wifi_connect_ms', 'boot_count'] as $field) {
            if (!property_exists($diagnostics, $field) || !is_int($diagnostics->{$field})) {
                throw new ApiException(422, 'INVALID_DIAGNOSTICS', sprintf('%s must be an integer', $field));
            }
        }
        if ($diagnostics->battery_mv < 0 || $diagnostics->battery_mv > 10000) {
            throw new ApiException(422, 'INVALID_BATTERY', 'battery_mv must be between 0 and 10000');
        }
        if ($diagnostics->rssi_dbm < -120 || $diagnostics->rssi_dbm > 0) {
            throw new ApiException(422, 'INVALID_RSSI', 'rssi_dbm must be between -120 and 0');
        }
        if ($diagnostics->wifi_connect_ms < 0 || $diagnostics->wifi_connect_ms > 120000) {
            throw new ApiException(422, 'INVALID_WIFI_CONNECT_TIME', 'wifi_connect_ms must be between 0 and 120000');
        }
        if ($diagnostics->boot_count < 0 || $diagnostics->boot_count > 4294967295) {
            throw new ApiException(422, 'INVALID_BOOT_COUNT', 'boot_count must be between 0 and 4294967295');
        }
        $errors = [];
        if (property_exists($diagnostics, 'errors')) {
            if (!is_array($diagnostics->errors) || count($diagnostics->errors) > 20) {
                throw new ApiException(422, 'INVALID_DIAGNOSTIC_ERRORS', 'errors must be an array with at most 20 codes');
            }
            foreach ($diagnostics->errors as $code) {
                if (!is_string($code) || preg_match('/^[A-Z0-9_.-]{1,64}$/', $code) !== 1) {
                    throw new ApiException(422, 'INVALID_DIAGNOSTIC_ERRORS', 'diagnostic error codes have an invalid format');
                }
                $errors[] = $code;
            }
        }

        return [
            'battery_mv' => $diagnostics->battery_mv,
            'rssi_dbm' => $diagnostics->rssi_dbm,
            'wifi_connect_ms' => $diagnostics->wifi_connect_ms,
            'boot_count' => $diagnostics->boot_count,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function validVersion(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/', $value) === 1;
    }

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/', $value) !== 1) {
            return null;
        }

        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.u\Z' : '!Y-m-d\TH:i:s\Z';
        $date = DateTimeImmutable::createFromFormat($format, $value, $this->utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    private function validateDefinition(stdClass $payload, string $definition): bool
    {
        $fragment = (object) [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$defs' => $this->schema->{'$defs'},
            '$ref' => '#/$defs/' . $definition,
        ];

        return $this->validator->validate($payload, $fragment)->isValid();
    }

    /** @return array{valid: false, code: string, message: string} */
    private function invalid(string $code, string $message): array
    {
        return ['valid' => false, 'code' => $code, 'message' => $message];
    }
}
