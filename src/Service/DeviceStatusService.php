<?php

declare(strict_types=1);

namespace Haccp\Service;

final class DeviceStatusService
{
    public function battery(?int $millivolts, int $lowThreshold, int $fullThreshold): string
    {
        if ($millivolts === null) {
            return 'unknown';
        }

        if ($millivolts >= $fullThreshold) {
            return 'full';
        }

        return $millivolts >= $lowThreshold ? 'medium' : 'low';
    }

    public function wifiBars(?int $rssiDbm): ?int
    {
        if ($rssiDbm === null) {
            return null;
        }

        return match (true) {
            $rssiDbm >= -55 => 4,
            $rssiDbm >= -67 => 3,
            $rssiDbm >= -75 => 2,
            default => 1,
        };
    }

    public function alarm(bool $enabled, ?float $minimum, ?float $maximum, ?float $temperature): string
    {
        if (!$enabled) {
            return 'disabled';
        }

        if ($temperature === null) {
            return 'no_data';
        }

        if ($minimum !== null && $temperature < $minimum) {
            return 'below_min';
        }

        if ($maximum !== null && $temperature > $maximum) {
            return 'above_max';
        }

        return 'normal';
    }

    /** @param list<string> $states */
    public function worstAlarm(array $states): string
    {
        if ($states === []) {
            return 'no_data';
        }

        foreach (['above_max', 'below_min', 'no_data', 'normal', 'disabled'] as $candidate) {
            if (in_array($candidate, $states, true)) {
                return $candidate;
            }
        }

        return 'no_data';
    }
}
