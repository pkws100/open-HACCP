<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Domain\Device;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\TransmissionRepository;
use Haccp\Support\Clock;
use PDO;
use stdClass;
use Throwable;

final readonly class HeartbeatService
{
    public function __construct(
        private PDO $pdo,
        private ProtocolValidator $validator,
        private DeviceRepository $devices,
        private TransmissionRepository $transmissions,
        private DeviceConfigService $configService,
        private ComplianceEventService $eventService,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function process(Device $device, stdClass $payload, string $requestId, ?string $remoteIp): array
    {
        $heartbeat = $this->validator->validateHeartbeat($payload);
        $now = $this->clock->now();
        $databaseNow = $this->clock->database($now);
        $configuration = $this->configService->get($device);

        $this->pdo->beginTransaction();
        try {
            $transmissionId = $this->transmissions->create([
                'device_id' => $device->id,
                'transmission_type' => 'heartbeat',
                'request_id' => $requestId,
                'batch_id' => null,
                'received_at' => $databaseNow,
                'firmware_version' => $heartbeat['firmware_version'],
                'hardware_revision' => $heartbeat['hardware_revision'],
                'device_info_json' => $heartbeat['device_info'] === null ? null : json_encode($heartbeat['device_info'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'operational_status_json' => $heartbeat['operational_status'] === null ? null : json_encode($heartbeat['operational_status'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'applied_config_version' => $heartbeat['config_ack']['applied_version'] ?? null,
                'config_apply_status' => $heartbeat['config_ack']['status'] ?? null,
                'queue_depth' => $heartbeat['operational_status']['queue_depth'] ?? null,
                'wifi_failures_since_report' => $heartbeat['operational_status']['wifi_failures_since_report'] ?? null,
                'upload_failures_since_report' => $heartbeat['operational_status']['upload_failures_since_report'] ?? null,
                'sleep_fallbacks_since_report' => $heartbeat['operational_status']['sleep_fallbacks_since_report'] ?? null,
                'battery_mv' => $heartbeat['battery_mv'],
                'rssi_dbm' => $heartbeat['rssi_dbm'],
                'wifi_connect_ms' => $heartbeat['wifi_connect_ms'],
                'boot_count' => $heartbeat['boot_count'],
                'measurement_count' => 0,
                'accepted_count' => 0,
                'duplicate_count' => 0,
                'rejected_count' => 0,
                'diagnostic_errors_json' => $heartbeat['errors'] === [] ? null : json_encode($heartbeat['errors'], JSON_THROW_ON_ERROR),
                'remote_ip' => $remoteIp,
                'created_at' => $databaseNow,
            ]);
            $this->devices->updateSeen(
                $device->id,
                (string) $heartbeat['firmware_version'],
                (string) $heartbeat['hardware_revision'],
                (int) $heartbeat['battery_mv'],
                (int) $heartbeat['rssi_dbm'],
                $remoteIp,
                $databaseNow,
                $heartbeat['device_info'],
                $heartbeat['config_ack'],
            );
            $this->eventService->diagnostics(
                $device->id,
                $transmissionId,
                (int) $heartbeat['battery_mv'],
                (int) $heartbeat['rssi_dbm'],
                $configuration,
                $databaseNow,
                $heartbeat['errors'],
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'success' => true,
            'protocol_version' => 1,
            'server_time' => $this->clock->api($now),
            'config_version' => $configuration['config_version'],
            'configuration' => $configuration,
        ];
    }
}
