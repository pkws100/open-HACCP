<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class TransmissionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO device_transmissions
             (device_id, transmission_type, request_id, batch_id, received_at, firmware_version,
              hardware_revision, battery_mv, rssi_dbm, wifi_connect_ms, boot_count, measurement_count,
              accepted_count, duplicate_count, rejected_count, remote_ip, created_at)
             VALUES
             (:device_id, :transmission_type, :request_id, :batch_id, :received_at, :firmware_version,
              :hardware_revision, :battery_mv, :rssi_dbm, :wifi_connect_ms, :boot_count, :measurement_count,
              :accepted_count, :duplicate_count, :rejected_count, :remote_ip, :created_at)',
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateCounts(int $id, int $accepted, int $duplicates, int $rejected): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE device_transmissions
             SET accepted_count = :accepted, duplicate_count = :duplicates, rejected_count = :rejected
             WHERE id = :id',
        );
        $statement->execute([
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'id' => $id,
        ]);
    }
}
