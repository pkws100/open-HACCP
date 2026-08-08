<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Domain\Device;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Repository\MeasurementRepository;
use Haccp\Repository\TransmissionRepository;
use Haccp\Support\Clock;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

final readonly class MeasurementService
{
    public function __construct(
        private PDO $pdo,
        private ProtocolValidator $validator,
        private DeviceRepository $devices,
        private MeasurementPointRepository $measurementPoints,
        private MeasurementRepository $measurements,
        private TransmissionRepository $transmissions,
        private DeviceConfigService $configService,
        private ComplianceEventService $eventService,
        private GapDetector $gapDetector,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function process(Device $device, stdClass $payload, string $requestId, ?string $remoteIp): array
    {
        $batch = $this->validator->validateBatchEnvelope($payload);
        $now = $this->clock->now();
        $receivedAt = $this->clock->database($now);
        $configuration = $this->configService->get($device);

        $this->pdo->beginTransaction();
        try {
            $diagnostics = $batch['diagnostics'];
            $transmissionId = $this->transmissions->create([
                'device_id' => $device->id,
                'transmission_type' => 'measurement_batch',
                'request_id' => $requestId,
                'batch_id' => $batch['batch_id'],
                'received_at' => $receivedAt,
                'firmware_version' => $batch['firmware_version'],
                'hardware_revision' => $batch['hardware_revision'],
                'battery_mv' => $diagnostics['battery_mv'],
                'rssi_dbm' => $diagnostics['rssi_dbm'],
                'wifi_connect_ms' => $diagnostics['wifi_connect_ms'],
                'boot_count' => $diagnostics['boot_count'],
                'measurement_count' => count($batch['measurements']),
                'accepted_count' => 0,
                'duplicate_count' => 0,
                'rejected_count' => 0,
                'diagnostic_errors_json' => $diagnostics['errors'] === [] ? null : json_encode($diagnostics['errors'], JSON_THROW_ON_ERROR),
                'remote_ip' => $remoteIp,
                'created_at' => $receivedAt,
            ]);

            $this->devices->updateSeen(
                $device->id,
                $batch['firmware_version'],
                $batch['hardware_revision'],
                $diagnostics['battery_mv'],
                $diagnostics['rssi_dbm'],
                $remoteIp,
                $receivedAt,
            );

            $acknowledgements = [];
            $rejections = [];
            $pointCache = [];
            $previousMax = [];
            $acknowledgedByPoint = [];
            $accepted = 0;
            $duplicates = 0;

            foreach ($batch['measurements'] as $index => $rawMeasurement) {
                $validation = $this->validator->validateMeasurement($rawMeasurement, $batch['sent_at']);
                if (!$validation['valid']) {
                    $rejections[] = $this->rejection(
                        $index,
                        $rawMeasurement,
                        $validation['code'],
                        $validation['message'],
                    );
                    continue;
                }

                $measurement = $validation['value'];
                $pointCode = $measurement['measurement_point'];
                if (!array_key_exists($pointCode, $pointCache)) {
                    $pointCache[$pointCode] = $this->measurementPoints->findActiveByDeviceAndCode($device->id, $pointCode);
                }
                $point = $pointCache[$pointCode];
                if ($point === null) {
                    $rejections[] = [
                        'index' => $index,
                        'measurement_point' => $pointCode,
                        'sequence' => $measurement['sequence'],
                        'code' => 'UNKNOWN_MEASUREMENT_POINT',
                        'message' => 'measurement_point is unknown or inactive for this device',
                    ];
                    continue;
                }

                $pointId = (int) $point['id'];
                if (!array_key_exists($pointCode, $previousMax)) {
                    $previousMax[$pointCode] = $this->measurements->maxSequence($device->id, $pointId);
                }

                $existing = $this->measurements->find($device->id, $pointId, $measurement['sequence']);
                if ($existing !== null) {
                    if (!$this->sameMeasurement($existing, $measurement)) {
                        $rejections[] = [
                            'index' => $index,
                            'measurement_point' => $pointCode,
                            'sequence' => $measurement['sequence'],
                            'code' => 'SEQUENCE_CONFLICT',
                            'message' => 'sequence already exists with different measurement data',
                        ];
                        continue;
                    }

                    $duplicates++;
                    $status = 'duplicate';
                } else {
                    try {
                        $measurementId = $this->measurements->insert($device->id, $pointId, $measurement, $receivedAt);
                        $this->eventService->measurement(
                            $device->id,
                            $pointId,
                            $measurementId,
                            (float) $measurement['temperature_c'],
                            (string) $measurement['measured_at_db'],
                            $configuration,
                        );
                        $accepted++;
                        $status = 'accepted';
                    } catch (PDOException $exception) {
                        if ($exception->getCode() !== '23000') {
                            throw $exception;
                        }
                        $existing = $this->measurements->find($device->id, $pointId, $measurement['sequence']);
                        if ($existing === null || !$this->sameMeasurement($existing, $measurement)) {
                            $rejections[] = [
                                'index' => $index,
                                'measurement_point' => $pointCode,
                                'sequence' => $measurement['sequence'],
                                'code' => 'SEQUENCE_CONFLICT',
                                'message' => 'sequence already exists with different measurement data',
                            ];
                            continue;
                        }
                        $duplicates++;
                        $status = 'duplicate';
                    }
                }

                $acknowledgements[] = [
                    'index' => $index,
                    'measurement_point' => $pointCode,
                    'sequence' => $measurement['sequence'],
                    'status' => $status,
                ];
                $acknowledgedByPoint[$pointCode][] = $measurement['sequence'];
            }

            $rejected = count($rejections);
            $gaps = $this->gapDetector->detect($acknowledgedByPoint, $previousMax);
            $this->eventService->diagnostics(
                $device->id,
                $transmissionId,
                (int) $diagnostics['battery_mv'],
                (int) $diagnostics['rssi_dbm'],
                $configuration,
                $receivedAt,
                $diagnostics['errors'],
            );
            foreach ($rejections as $rejection) {
                $this->eventService->rejection($device->id, $receivedAt, $rejection, $transmissionId);
            }
            foreach ($gaps as $gap) {
                $this->eventService->sequenceGap($device->id, $receivedAt, $gap, $transmissionId);
            }
            $this->transmissions->updateCounts($transmissionId, $accepted, $duplicates, $rejected);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $lastSequence = null;
        foreach ($acknowledgements as $acknowledgement) {
            $lastSequence = max($lastSequence ?? 0, $acknowledgement['sequence']);
        }

        $this->logger->info('measurement_batch_processed', [
            'request_id' => $requestId,
            'device_uid' => $device->uid,
            'batch_id' => $batch['batch_id'],
            'measurement_count' => count($batch['measurements']),
            'accepted_count' => $accepted,
            'duplicate_count' => $duplicates,
            'rejected_count' => $rejected,
            'sequence_gap_count' => count($gaps),
        ]);

        return [
            'success' => true,
            'protocol_version' => 1,
            'server_time' => $this->clock->api($now),
            'batch_id' => $batch['batch_id'],
            'result' => [
                'received' => count($batch['measurements']),
                'accepted' => $accepted,
                'duplicates' => $duplicates,
                'rejected' => $rejected,
                'last_sequence' => $lastSequence,
            ],
            'acknowledgements' => $acknowledgements,
            'rejections' => $rejections,
            'sequence_gaps' => $gaps,
            'config_version' => $configuration['config_version'],
            'configuration' => $configuration,
        ];
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $measurement */
    private function sameMeasurement(array $existing, array $measurement): bool
    {
        $existingTime = new \DateTimeImmutable((string) $existing['measured_at'], new \DateTimeZone('UTC'));

        return $existingTime->format('Y-m-d H:i:s.u') === $measurement['measured_at_db']
            && number_format((float) $existing['temperature_c'], 3, '.', '') === $measurement['temperature_c_db']
            && number_format((float) $existing['humidity_rh'], 3, '.', '') === $measurement['humidity_rh_db']
            && (int) $existing['battery_mv'] === $measurement['battery_mv'];
    }

    /** @return array<string, mixed> */
    private function rejection(int $index, stdClass $raw, string $code, string $message): array
    {
        return [
            'index' => $index,
            'measurement_point' => is_string($raw->measurement_point ?? null) ? $raw->measurement_point : null,
            'sequence' => is_int($raw->sequence ?? null) ? $raw->sequence : null,
            'code' => $code,
            'message' => $message,
        ];
    }
}
