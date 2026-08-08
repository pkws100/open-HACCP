<?php

declare(strict_types=1);

namespace Haccp\Demo;

use DateTimeImmutable;

final class DemoPayloadFactory
{
    /** @param array<string, mixed> $profile @return array<string, mixed> */
    public function heartbeat(array $profile, int $bootCount): array
    {
        return [
            'protocol_version' => 1,
            'firmware_version' => '1.0.0-demo',
            'hardware_revision' => 'simulator-v1',
            'battery_mv' => $profile['battery_mv'],
            'rssi_dbm' => $profile['rssi_dbm'],
            'wifi_connect_ms' => 420 + ($bootCount % 9) * 17,
            'boot_count' => $bootCount,
        ];
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    public function batch(array $profile, int $firstSequence, int $count, int $bootCount, int $uploadCount, DateTimeImmutable $sentAt): array
    {
        $measurements = [];
        for ($index = 0; $index < $count; $index++) {
            $sequence = $firstSequence + $index;
            $ageSteps = $count === 1 ? 0 : $count - $index;
            $temperatureOffset = (($sequence % 7) - 3) * 0.07;
            $humidityOffset = (($sequence % 5) - 2) * 0.2;
            $measurements[] = [
                'measurement_point' => $profile['measurement_point'],
                'sequence' => $sequence,
                'measured_at' => $sentAt->modify(sprintf('-%d minutes', $ageSteps * 5))->format('Y-m-d\TH:i:s\Z'),
                'temperature_c' => round((float) $profile['temperature_c'] + $temperatureOffset, 2),
                'humidity_rh' => round((float) $profile['humidity_rh'] + $humidityOffset, 2),
                'battery_mv' => (int) $profile['battery_mv'],
            ];
        }

        return [
            'protocol_version' => 1,
            'batch_id' => sprintf('demo-%08d-%08d', $bootCount, $uploadCount),
            'firmware_version' => '1.0.0-demo',
            'hardware_revision' => 'simulator-v1',
            'sent_at' => $sentAt->format('Y-m-d\TH:i:s\Z'),
            'diagnostics' => $this->heartbeat($profile, $bootCount),
            'measurements' => $measurements,
        ];
    }

    /** @param array<string, mixed> $batch @param array<string, mixed> $response */
    public function isAcknowledged(array $batch, array $response): bool
    {
        if (($response['success'] ?? false) !== true || ($response['batch_id'] ?? null) !== $batch['batch_id']) {
            return false;
        }

        $acknowledgements = $response['acknowledgements'] ?? null;
        if (!is_array($acknowledgements) || count($acknowledgements) !== count($batch['measurements'])) {
            return false;
        }

        foreach ($batch['measurements'] as $index => $measurement) {
            $matches = array_filter($acknowledgements, static fn (mixed $ack): bool => is_array($ack)
                && ($ack['index'] ?? null) === $index
                && ($ack['measurement_point'] ?? null) === $measurement['measurement_point']
                && ($ack['sequence'] ?? null) === $measurement['sequence']
                && in_array($ack['status'] ?? null, ['accepted', 'duplicate'], true));
            if (count($matches) !== 1) {
                return false;
            }
        }

        return true;
    }
}
