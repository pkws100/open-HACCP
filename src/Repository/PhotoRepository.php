<?php

declare(strict_types=1);

namespace Haccp\Repository;

use PDO;

final readonly class PhotoRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forMeasurementPoint(int $measurementPointId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*, u.display_name AS created_by_name
             FROM measurement_point_photos p
             INNER JOIN users u ON u.id = p.created_by_user_id
             WHERE p.measurement_point_id = :measurement_point_id AND p.deleted_at IS NULL
             ORDER BY p.revision DESC',
        );
        $statement->execute(['measurement_point_id' => $measurementPointId]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findActiveByPublicId(string $publicId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.*, mp.name AS measurement_point_name, mp.location, d.name AS device_name
             FROM measurement_point_photos p
             INNER JOIN measurement_points mp ON mp.id = p.measurement_point_id
             INNER JOIN devices d ON d.id = mp.device_id
             WHERE p.public_id = :public_id AND p.deleted_at IS NULL',
        );
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function lockActiveByPublicId(string $publicId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM measurement_point_photos
             WHERE public_id = :public_id AND deleted_at IS NULL FOR UPDATE',
        );
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function lockMeasurementPoint(int $measurementPointId): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM measurement_points WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $measurementPointId]);

        return $statement->fetchColumn() !== false;
    }

    public function nextRevision(int $measurementPointId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(revision), 0) + 1 FROM measurement_point_photos WHERE measurement_point_id = :id',
        );
        $statement->execute(['id' => $measurementPointId]);

        return (int) $statement->fetchColumn();
    }

    public function clearCurrent(int $measurementPointId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE measurement_point_photos SET is_current = 0
             WHERE measurement_point_id = :measurement_point_id AND is_current = 1',
        );
        $statement->execute(['measurement_point_id' => $measurementPointId]);
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO measurement_point_photos
             (public_id, measurement_point_id, revision, is_current, full_path, thumbnail_path, mime_type,
              width, height, full_size, thumbnail_size, full_sha256, thumbnail_sha256,
              created_by_user_id, created_at)
             VALUES
             (:public_id, :measurement_point_id, :revision, 1, :full_path, :thumbnail_path, :mime_type,
              :width, :height, :full_size, :thumbnail_size, :full_sha256, :thumbnail_sha256,
              :created_by_user_id, :created_at)',
        );
        $statement->execute($values);
    }

    public function tombstone(int $id, int $userId, string $deletedAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE measurement_point_photos
             SET is_current = 0, deleted_by_user_id = :deleted_by, deleted_at = :deleted_at
             WHERE id = :id',
        );
        $statement->execute(['deleted_by' => $userId, 'deleted_at' => $deletedAt, 'id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function promoteLatest(int $measurementPointId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, public_id FROM measurement_point_photos
             WHERE measurement_point_id = :measurement_point_id AND deleted_at IS NULL
             ORDER BY revision DESC LIMIT 1',
        );
        $statement->execute(['measurement_point_id' => $measurementPointId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $update = $this->pdo->prepare('UPDATE measurement_point_photos SET is_current = 1 WHERE id = :id');
        $update->execute(['id' => $row['id']]);

        return $row;
    }
}
