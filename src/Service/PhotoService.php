<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Api\ApiException;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Repository\PhotoRepository;
use Haccp\Support\Clock;
use Imagick;
use PDO;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

final readonly class PhotoService
{
    private const MAX_PIXELS = 24_000_000;
    private const FULL_MAX_EDGE = 2048;
    private const THUMBNAIL_WIDTH = 480;
    private const THUMBNAIL_HEIGHT = 320;
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    public function __construct(
        private PDO $pdo,
        private PhotoRepository $photos,
        private MeasurementPointRepository $measurementPoints,
        private AuthService $auth,
        private AuditService $audit,
        private Clock $clock,
        private string $mediaPath,
        private int $maxUploadBytes,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(int $measurementPointId): array
    {
        $point = $this->measurementPoints->findById($measurementPointId);
        if ($point === null) {
            throw new ApiException(404, 'MEASUREMENT_POINT_NOT_FOUND', 'Die Messstelle wurde nicht gefunden.');
        }

        return [
            'success' => true,
            'measurement_point' => $this->point($point),
            'photos' => array_map(fn (array $row): array => $this->photo($row), $this->photos->forMeasurementPoint($measurementPointId)),
        ];
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function upload(int $measurementPointId, UploadedFileInterface $upload, array $user): array
    {
        $point = $this->measurementPoints->findById($measurementPointId);
        if ($point === null) {
            throw new ApiException(404, 'MEASUREMENT_POINT_NOT_FOUND', 'Die Messstelle wurde nicht gefunden.');
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ApiException(422, 'INVALID_IMAGE', 'Das Foto konnte nicht vollständig hochgeladen werden.');
        }
        $size = $upload->getSize();
        if ($size === null || $size <= 0) {
            throw new ApiException(422, 'INVALID_IMAGE', 'Die Bilddatei ist leer.');
        }
        if ($size > $this->maxUploadBytes) {
            throw new ApiException(413, 'PHOTO_TOO_LARGE', 'Das Foto überschreitet die zulässige Größe von 12 MiB.');
        }

        $this->ensureMediaDirectory();
        $publicId = $this->uuid();
        $relativeDirectory = substr(str_replace('-', '', $publicId), 0, 2) . '/' . $publicId;
        $directory = $this->mediaPath . '/' . $relativeDirectory;
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Photo directory could not be created.');
        }
        $input = $directory . '/upload.tmp';
        $full = $directory . '/full.webp.tmp';
        $thumbnail = $directory . '/thumbnail.webp.tmp';

        try {
            $source = $upload->getStream();
            $target = fopen($input, 'wb');
            if ($target === false) {
                throw new RuntimeException('Photo staging file could not be opened.');
            }
            while (!$source->eof()) {
                $chunk = $source->read(1_048_576);
                if ($chunk === '') {
                    break;
                }
                fwrite($target, $chunk);
            }
            fclose($target);
            if (filesize($input) > $this->maxUploadBytes) {
                throw new ApiException(413, 'PHOTO_TOO_LARGE', 'Das Foto überschreitet die zulässige Größe von 12 MiB.');
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($input);
            if (!is_string($mime) || !in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                throw new ApiException(415, 'UNSUPPORTED_IMAGE_FORMAT', 'Zulässig sind JPEG, PNG, WebP, HEIC und HEIF.');
            }

            $processed = $this->process($input, $full, $thumbnail);
            $finalFull = $directory . '/full.webp';
            $finalThumbnail = $directory . '/thumbnail.webp';
            if (!rename($full, $finalFull) || !rename($thumbnail, $finalThumbnail)) {
                throw new RuntimeException('Processed photo could not be finalized.');
            }
            @unlink($input);

            $this->pdo->beginTransaction();
            if (!$this->photos->lockMeasurementPoint($measurementPointId)) {
                throw new ApiException(404, 'MEASUREMENT_POINT_NOT_FOUND', 'Die Messstelle wurde nicht gefunden.');
            }
            $revision = $this->photos->nextRevision($measurementPointId);
            $this->photos->clearCurrent($measurementPointId);
            $createdAt = $this->clock->database($this->clock->now());
            $this->photos->create([
                'public_id' => $publicId,
                'measurement_point_id' => $measurementPointId,
                'revision' => $revision,
                'full_path' => $relativeDirectory . '/full.webp',
                'thumbnail_path' => $relativeDirectory . '/thumbnail.webp',
                'mime_type' => 'image/webp',
                'width' => $processed['width'],
                'height' => $processed['height'],
                'full_size' => filesize($finalFull),
                'thumbnail_size' => filesize($finalThumbnail),
                'full_sha256' => hash_file('sha256', $finalFull),
                'thumbnail_sha256' => hash_file('sha256', $finalThumbnail),
                'created_by_user_id' => (int) $user['id'],
                'created_at' => $createdAt,
            ]);
            $this->pdo->commit();

            $row = $this->photos->findActiveByPublicId($publicId);
            if ($row === null) {
                throw new RuntimeException('Created photo could not be loaded.');
            }
            $this->audit->append('measurement_point.photo_uploaded', (int) $user['id'], 'measurement_point', (string) $measurementPointId, [
                'photo_id' => $publicId,
                'revision' => $revision,
                'sha256' => $row['full_sha256'],
            ]);

            return ['success' => true, 'photo' => $this->photo($row)];
        } catch (ApiException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->removeDirectory($directory);
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->removeDirectory($directory);
            throw new ApiException(422, 'IMAGE_PROCESSING_FAILED', 'Das Foto konnte nicht sicher verarbeitet werden.');
        }
    }

    /** @return array<string, mixed> */
    public function file(string $publicId, string $variant): array
    {
        if (!in_array($variant, ['thumbnail', 'full'], true)) {
            throw new ApiException(404, 'PHOTO_NOT_FOUND', 'Das Foto wurde nicht gefunden.');
        }
        $row = $this->photos->findActiveByPublicId($publicId);
        if ($row === null) {
            throw new ApiException(404, 'PHOTO_NOT_FOUND', 'Das Foto wurde nicht gefunden.');
        }
        $relativePath = (string) $row[$variant . '_path'];
        $path = $this->mediaPath . '/' . ltrim($relativePath, '/');
        if (!is_file($path) || !is_readable($path)) {
            throw new ApiException(404, 'PHOTO_NOT_FOUND', 'Das Foto wurde nicht gefunden.');
        }

        return [
            'path' => $path,
            'mime_type' => (string) $row['mime_type'],
            'size' => (int) $row[$variant . '_size'],
            'sha256' => (string) $row[$variant . '_sha256'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function delete(string $publicId, string $currentPassword, array $user): array
    {
        if ((string) ($user['role'] ?? '') !== 'administrator') {
            throw new ApiException(403, 'FORBIDDEN', 'Nur Administratoren dürfen Fotos endgültig löschen.');
        }
        if (!$this->auth->verifyPassword($user, $currentPassword)) {
            throw new ApiException(422, 'CURRENT_PASSWORD_INVALID', 'Das aktuelle Passwort ist nicht korrekt.');
        }

        $this->pdo->beginTransaction();
        try {
            $row = $this->photos->lockActiveByPublicId($publicId);
            if ($row === null) {
                throw new ApiException(404, 'PHOTO_NOT_FOUND', 'Das Foto wurde nicht gefunden.');
            }
            $pointId = (int) $row['measurement_point_id'];
            $wasCurrent = (bool) $row['is_current'];
            $this->photos->tombstone((int) $row['id'], (int) $user['id'], $this->clock->database($this->clock->now()));
            $newCurrent = $wasCurrent ? $this->photos->promoteLatest($pointId) : null;
            $this->pdo->commit();

            @unlink($this->mediaPath . '/' . ltrim((string) $row['full_path'], '/'));
            @unlink($this->mediaPath . '/' . ltrim((string) $row['thumbnail_path'], '/'));
            @rmdir(dirname($this->mediaPath . '/' . ltrim((string) $row['full_path'], '/')));
            $this->audit->append('measurement_point.photo_deleted', (int) $user['id'], 'measurement_point', (string) $pointId, [
                'photo_id' => $publicId,
                'revision' => (int) $row['revision'],
                'sha256' => (string) $row['full_sha256'],
            ]);

            return ['success' => true, 'current_photo_id' => $newCurrent['public_id'] ?? null];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{width: int, height: int} */
    private function process(string $input, string $full, string $thumbnail): array
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 192 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 256 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 1);
        $image = new Imagick();
        $image->readImage($input);
        if ($image->getNumberImages() !== 1) {
            $image->clear();
            throw new ApiException(422, 'INVALID_IMAGE', 'Bildserien und animierte Bilder werden nicht unterstützt.');
        }
        $image->setIteratorIndex(0);
        $image->autoOrient();
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_PIXELS) {
            $image->clear();
            throw new ApiException(413, 'PHOTO_TOO_LARGE', 'Das Foto überschreitet die zulässige Auflösung von 24 Megapixeln.');
        }
        $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        $image->stripImage();
        if ($width > self::FULL_MAX_EDGE || $height > self::FULL_MAX_EDGE) {
            $image->thumbnailImage(self::FULL_MAX_EDGE, self::FULL_MAX_EDGE, true);
        }
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(85);
        $image->setOption('webp:method', '5');
        $image->writeImage($full);
        $finalWidth = $image->getImageWidth();
        $finalHeight = $image->getImageHeight();

        $thumb = clone $image;
        $thumb->cropThumbnailImage(self::THUMBNAIL_WIDTH, self::THUMBNAIL_HEIGHT);
        $thumb->stripImage();
        $thumb->setImageFormat('webp');
        $thumb->setImageCompressionQuality(82);
        $thumb->writeImage($thumbnail);
        $thumb->clear();
        $image->clear();

        return ['width' => $finalWidth, 'height' => $finalHeight];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function photo(array $row): array
    {
        $publicId = (string) $row['public_id'];

        return [
            'photo_id' => $publicId,
            'revision' => (int) $row['revision'],
            'is_current' => (bool) $row['is_current'],
            'thumbnail_url' => '/api/v1/dashboard/photos/' . rawurlencode($publicId) . '/thumbnail',
            'full_url' => '/api/v1/dashboard/photos/' . rawurlencode($publicId) . '/full',
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'size' => (int) $row['full_size'],
            'sha256' => (string) $row['full_sha256'],
            'created_at' => $this->timestamp((string) $row['created_at']),
            'created_by' => $row['created_by_name'] ?? null,
        ];
    }

    /** @param array<string, mixed> $point @return array<string, mixed> */
    private function point(array $point): array
    {
        return [
            'id' => (int) $point['id'],
            'code' => (string) $point['code'],
            'name' => (string) $point['name'],
            'location' => $point['location'],
            'device_uid' => (string) $point['device_uid'],
            'device_name' => (string) $point['device_name'],
        ];
    }

    private function ensureMediaDirectory(): void
    {
        if (!is_dir($this->mediaPath) && !mkdir($this->mediaPath, 0750, true) && !is_dir($this->mediaPath)) {
            throw new RuntimeException('Media directory could not be created.');
        }
        if (!is_writable($this->mediaPath)) {
            throw new RuntimeException('Media directory is not writable.');
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($directory);
    }
}
