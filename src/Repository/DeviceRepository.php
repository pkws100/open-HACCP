<?php

declare(strict_types=1);

namespace Haccp\Repository;

use Haccp\Domain\Device;
use PDO;

final readonly class DeviceRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByUid(string $uid): ?Device
    {
        $statement = $this->pdo->prepare('SELECT id, device_uid, name, status, api_key_hash FROM devices WHERE device_uid = :uid');
        $statement->execute(['uid' => $uid]);
        $row = $statement->fetch();

        return $row === false ? null : new Device(
            (int) $row['id'],
            (string) $row['device_uid'],
            (string) $row['name'],
            (string) $row['status'],
            (string) $row['api_key_hash'],
        );
    }

    public function create(string $uid, string $name, string $apiKeyHash, string $now): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO devices (device_uid, name, status, api_key_hash, created_at, updated_at)
             VALUES (:uid, :name, :status, :api_key_hash, :created_at, :updated_at)',
        );
        $statement->execute([
            'uid' => $uid,
            'name' => $name,
            'status' => 'active',
            'api_key_hash' => $apiKeyHash,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $statement = $this->pdo->query(
            'SELECT device_uid, name, status, hardware_revision, firmware_version, last_seen_at, created_at
             FROM devices ORDER BY device_uid',
        );

        return $statement->fetchAll();
    }

    public function disable(string $uid, string $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE devices SET status = :status, updated_at = :updated_at WHERE device_uid = :uid',
        );
        $statement->execute(['status' => 'disabled', 'updated_at' => $now, 'uid' => $uid]);

        return $statement->rowCount() > 0 || $this->findByUid($uid) !== null;
    }

    public function activateAndRename(int $deviceId, string $name, string $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE devices SET name = :name, status = :status, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute(['name' => $name, 'status' => 'active', 'updated_at' => $now, 'id' => $deviceId]);
    }

    public function updateApiKey(int $deviceId, string $apiKeyHash, string $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE devices SET api_key_hash = :api_key_hash, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute(['api_key_hash' => $apiKeyHash, 'updated_at' => $now, 'id' => $deviceId]);
    }

    public function updateSeen(
        int $deviceId,
        string $firmwareVersion,
        string $hardwareRevision,
        int $batteryMv,
        int $rssiDbm,
        ?string $remoteIp,
        string $now,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE devices
             SET last_seen_at = :last_seen_at, last_ip = :last_ip, last_rssi_dbm = :last_rssi_dbm,
                 last_battery_mv = :last_battery_mv, firmware_version = :firmware_version,
                 hardware_revision = :hardware_revision, updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'last_seen_at' => $now,
            'last_ip' => $remoteIp,
            'last_rssi_dbm' => $rssiDbm,
            'last_battery_mv' => $batteryMv,
            'firmware_version' => $firmwareVersion,
            'hardware_revision' => $hardwareRevision,
            'updated_at' => $now,
            'id' => $deviceId,
        ]);
    }
}
