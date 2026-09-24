<?php

declare(strict_types=1);

namespace Haccp\Service;

use JsonException;
use RuntimeException;

/** Server-side temperature correction; the sensor protocol always transports raw values. */
final class TemperatureCalibration
{
    /** @return array<string, mixed> */
    public static function extension(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Device configuration extension is invalid.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Device configuration extension is invalid.');
        }

        return $decoded;
    }

    /** @return array<string, float> */
    public static function offsets(?string $json): array
    {
        $raw = self::extension($json)['temperature_offsets_c'] ?? [];
        if (!is_array($raw)) {
            throw new RuntimeException('Device temperature calibration is invalid.');
        }
        $offsets = [];
        foreach ($raw as $code => $offset) {
            if (!is_string($code) || (!is_int($offset) && !is_float($offset))
                || !is_finite((float) $offset) || $offset < -10 || $offset > 10) {
                throw new RuntimeException('Device temperature calibration is invalid.');
            }
            $offsets[$code] = round((float) $offset, 3);
        }

        return $offsets;
    }
}
