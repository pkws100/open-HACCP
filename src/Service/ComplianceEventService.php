<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Repository\EventRepository;
use Haccp\Support\Clock;
use PDO;

final readonly class ComplianceEventService
{
    public function __construct(private PDO $pdo, private EventRepository $events, private Clock $clock)
    {
    }

    public function measurement(int $deviceId, int $pointId, int $measurementId, float $temperature, string $measuredAt, array $configuration): void
    {
        $this->lockDevice($deviceId);
        $alarm = $configuration['alarm'] ?? [];
        if (($alarm['enabled'] ?? false) !== true || $alarm['temperature_min_c'] === null || $alarm['temperature_max_c'] === null) {
            $this->closeState($deviceId, $pointId, 'temperature_below_min', $measuredAt);
            $this->closeState($deviceId, $pointId, 'temperature_above_max', $measuredAt);
            return;
        }
        $min = (float) $alarm['temperature_min_c'];
        $max = (float) $alarm['temperature_max_c'];
        if ($temperature < $min) {
            $this->closeState($deviceId, $pointId, 'temperature_above_max', $measuredAt);
            $this->openState($deviceId, $pointId, 'temperature_below_min', 'critical', $measuredAt, $temperature, $min, $max, $measurementId, null);
        } elseif ($temperature > $max) {
            $this->closeState($deviceId, $pointId, 'temperature_below_min', $measuredAt);
            $this->openState($deviceId, $pointId, 'temperature_above_max', 'critical', $measuredAt, $temperature, $min, $max, $measurementId, null);
        } else {
            $this->closeState($deviceId, $pointId, 'temperature_below_min', $measuredAt);
            $this->closeState($deviceId, $pointId, 'temperature_above_max', $measuredAt);
        }
    }

    public function diagnostics(int $deviceId, int $transmissionId, int $batteryMv, int $rssiDbm, array $configuration, string $at, array $errorCodes = []): void
    {
        $this->lockDevice($deviceId);
        $low = (int) ($configuration['battery']['low_threshold_mv'] ?? 5600);
        if ($batteryMv < $low) {
            $this->openState($deviceId, null, 'battery_low', 'warning', $at, $batteryMv, $low, null, null, $transmissionId);
        } else {
            $this->closeState($deviceId, null, 'battery_low', $at);
        }
        if ($rssiDbm < -75) {
            $this->openState($deviceId, null, 'signal_weak', 'warning', $at, $rssiDbm, -75, null, null, $transmissionId);
        } else {
            $this->closeState($deviceId, null, 'signal_weak', $at);
        }
        $this->closeState($deviceId, null, 'device_offline', $at);
        $codes = array_values(array_unique(array_filter($errorCodes, static fn (mixed $code): bool => is_string($code) && preg_match('/^[A-Z0-9_.-]{1,64}$/', $code) === 1)));
        if ($codes === []) {
            $this->closeState($deviceId, null, 'firmware_diagnostic', $at);
        } else {
            $existing = $this->events->openEvent($deviceId, null, 'firmware_diagnostic');
            if ($existing === null) {
                $this->discrete($deviceId, null, 'firmware_diagnostic', 'warning', $at, ['codes' => $codes], null, $transmissionId);
            } else {
                $this->events->updateMetadata((int) $existing['id'], ['codes' => $codes], $at);
            }
        }
    }

    public function rejection(int $deviceId, string $at, array $rejection, int $transmissionId): void
    {
        $this->discrete($deviceId, null, 'measurement_rejected', 'warning', $at, [
            'code' => $rejection['code'] ?? 'UNKNOWN',
            'measurement_point' => $rejection['measurement_point'] ?? null,
            'sequence' => $rejection['sequence'] ?? null,
        ], null, $transmissionId);
    }

    public function sequenceGap(int $deviceId, string $at, array $gap, int $transmissionId): void
    {
        $this->discrete($deviceId, null, 'sequence_gap', 'warning', $at, $gap, null, $transmissionId);
    }

    public function reconcileOffline(): int
    {
        $now = $this->clock->now();
        $deviceIds = $this->pdo->query("SELECT id FROM devices WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $opened = 0;
        foreach ($deviceIds as $deviceId) {
            $this->pdo->beginTransaction();
            try {
                $statement = $this->pdo->prepare(
                    'SELECT d.last_seen_at, d.created_at, dc.upload_interval_seconds
                     FROM devices d INNER JOIN device_configs dc ON dc.id = (
                         SELECT latest.id FROM device_configs latest WHERE latest.device_id = d.id ORDER BY latest.config_version DESC LIMIT 1
                     ) WHERE d.id = :device_id AND d.status = \'active\' FOR UPDATE',
                );
                $statement->execute(['device_id' => $deviceId]);
                $row = $statement->fetch();
                if ($row !== false) {
                    $interval = (int) $row['upload_interval_seconds'];
                    $threshold = max($interval * 2, $interval + 900);
                    $activityAt = new \DateTimeImmutable((string) ($row['last_seen_at'] ?? $row['created_at']), new \DateTimeZone('UTC'));
                    if ($activityAt->getTimestamp() < $now->getTimestamp() - $threshold) {
                        $before = $this->events->openEvent((int) $deviceId, null, 'device_offline');
                        $this->openState((int) $deviceId, null, 'device_offline', 'critical', $this->clock->database($now), null, null, null, null, null);
                        if ($before === null) {
                            $opened++;
                        }
                    }
                }
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        }

        return $opened;
    }

    private function openState(int $deviceId, ?int $pointId, string $type, string $severity, string $at, float|int|null $value, float|int|null $min, float|int|null $max, ?int $measurementId, ?int $transmissionId): void
    {
        $existing = $this->events->openEvent($deviceId, $pointId, $type);
        if ($existing !== null) {
            if ($value !== null) {
                $this->events->updateObserved((int) $existing['id'], $value, $at);
            }
            return;
        }
        $this->events->create([
            'device_id' => $deviceId,
            'measurement_point_id' => $pointId,
            'event_type' => $type,
            'severity' => $severity,
            'state' => 'open',
            'opened_at' => $at,
            'threshold_min' => $min,
            'threshold_max' => $max,
            'observed_value' => $value,
            'source_measurement_id' => $measurementId,
            'source_transmission_id' => $transmissionId,
            'metadata_json' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function closeState(int $deviceId, ?int $pointId, string $type, string $at): void
    {
        $existing = $this->events->openEvent($deviceId, $pointId, $type);
        if ($existing !== null) {
            $this->events->close((int) $existing['id'], $at);
        }
    }

    private function discrete(int $deviceId, ?int $pointId, string $type, string $severity, string $at, array $metadata, ?int $measurementId, ?int $transmissionId): void
    {
        $this->events->create([
            'device_id' => $deviceId,
            'measurement_point_id' => $pointId,
            'event_type' => $type,
            'severity' => $severity,
            'state' => 'open',
            'opened_at' => $at,
            'threshold_min' => null,
            'threshold_max' => null,
            'observed_value' => null,
            'source_measurement_id' => $measurementId,
            'source_transmission_id' => $transmissionId,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function lockDevice(int $deviceId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Compliance event transitions require an active transaction.');
        }
        $statement = $this->pdo->prepare('SELECT id FROM devices WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $deviceId]);
    }
}
