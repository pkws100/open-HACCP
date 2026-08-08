<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Api\ApiException;
use Haccp\Repository\ExportRepository;
use Haccp\Support\Clock;

final readonly class ExportService
{
    private const EXTENDED_FIELDS = ['humidity', 'battery', 'battery_forecast', 'rssi', 'wifi_timing', 'firmware', 'sequences', 'received_at', 'transmissions', 'configuration'];

    public function __construct(
        private ExportRepository $exports,
        private ComplianceService $compliance,
        private AuditService $audit,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(object $payload, array $user): array
    {
        $mode = is_string($payload->mode ?? null) ? $payload->mode : '';
        $format = is_string($payload->format ?? null) ? $payload->format : '';
        $legalProfile = is_string($payload->legal_profile ?? null) ? $payload->legal_profile : 'configured';
        if (!in_array($mode, ['authority', 'extended'], true) || !in_array($format, ['pdf', 'xlsx', 'csv'], true)) {
            throw new ApiException(422, 'INVALID_EXPORT_REQUEST', 'Modus oder Format des Exports ist ungültig.');
        }
        if (!in_array($legalProfile, ['configured', 'general_haccp', 'quick_frozen'], true)) {
            throw new ApiException(422, 'INVALID_EXPORT_REQUEST', 'Das Rechtsprofil des Exports ist ungültig.');
        }
        if ($mode === 'extended' && $user['role'] === 'auditor') {
            throw new ApiException(403, 'FORBIDDEN', 'Prüfer dürfen nur Behörden-Nachweise erzeugen.');
        }
        $from = $this->date($payload->from ?? null, false);
        $to = $this->date($payload->to ?? null, true);
        if ($from > $to) {
            throw new ApiException(422, 'INVALID_EXPORT_REQUEST', 'Der Exportzeitraum ist ungültig.');
        }
        $pointIds = $this->integers($payload->measurement_point_ids ?? []);
        $deviceUids = $this->strings($payload->device_uids ?? []);
        $fields = $mode === 'extended' ? $this->strings($payload->extended_fields ?? []) : [];
        if (array_diff($fields, self::EXTENDED_FIELDS) !== []) {
            throw new ApiException(422, 'INVALID_EXPORT_REQUEST', 'Mindestens ein Zusatzfeld ist unbekannt.');
        }
        $preflight = $this->compliance->preflight($pointIds, $deviceUids, $legalProfile);
        $chunks = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $chunkEnd = $cursor->modify('+365 days')->setTime(23, 59, 59);
            if ($chunkEnd > $to) {
                $chunkEnd = $to;
            }
            $chunks[] = [$cursor, $chunkEnd];
            $cursor = $chunkEnd->modify('+1 second');
            if (count($chunks) > 20) {
                throw new ApiException(422, 'INVALID_EXPORT_REQUEST', 'Der Gesamtzeitraum ist für eine automatische Aufteilung zu lang.');
            }
        }
        $jobs = [];
        foreach ($chunks as $chunkIndex => [$chunkFrom, $chunkTo]) {
            $parameters = [
                'from' => $chunkFrom->format(DATE_ATOM),
                'to' => $chunkTo->format(DATE_ATOM),
                'from_db' => $this->clock->database($chunkFrom),
                'to_db' => $this->clock->database($chunkTo),
                'device_uids' => $deviceUids,
                'measurement_point_ids' => $pointIds,
                'extended_fields' => array_values(array_unique($fields)),
                'legal_profile' => $legalProfile,
                'preflight' => $preflight,
                'split' => ['part' => $chunkIndex + 1, 'total' => count($chunks)],
            ];
            $publicId = $this->uuid();
            $now = $this->clock->database($this->clock->now());
            $id = $this->exports->createJob([
                'public_id' => $publicId,
                'requested_by_user_id' => (int) $user['id'],
                'status' => 'queued',
                'mode' => $mode,
                'format' => $format,
                'parameters_json' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'draft' => $preflight['complete'] ? 0 : 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->append('export.requested', (int) $user['id'], 'export_job', $publicId, ['mode' => $mode, 'format' => $format, 'draft' => !$preflight['complete'], 'part' => $chunkIndex + 1, 'parts' => count($chunks)]);
            $jobs[] = $this->normalize($this->exports->job($publicId) ?? ['id' => $id, 'public_id' => $publicId]);
        }

        return ['job' => $jobs[0], 'jobs' => $jobs, 'split' => count($jobs) > 1];
    }

    /** @return list<array<string, mixed>> */
    public function list(array $user): array
    {
        $userId = $user['role'] === 'auditor' ? (int) $user['id'] : null;

        return array_map($this->normalize(...), $this->exports->jobs($userId));
    }

    /** @return array<string, mixed> */
    public function get(string $publicId, array $user): array
    {
        $job = $this->exports->job($publicId);
        if ($job === null) {
            throw new ApiException(404, 'EXPORT_NOT_FOUND', 'Der Exportauftrag wurde nicht gefunden.');
        }
        if ($user['role'] === 'auditor' && (int) $job['requested_by_user_id'] !== (int) $user['id']) {
            throw new ApiException(403, 'EXPORT_ACCESS_DENIED', 'Auf diesen Export darf nicht zugegriffen werden.');
        }

        return $this->normalize($job);
    }

    /** @return array<string, mixed> */
    public function download(string $publicId, array $user): array
    {
        $job = $this->exports->job($publicId);
        if ($job === null) {
            throw new ApiException(404, 'EXPORT_NOT_FOUND', 'Der Exportauftrag wurde nicht gefunden.');
        }
        if ($user['role'] === 'auditor' && (int) $job['requested_by_user_id'] !== (int) $user['id']) {
            throw new ApiException(403, 'EXPORT_ACCESS_DENIED', 'Auf diesen Export darf nicht zugegriffen werden.');
        }
        if ($job['status'] === 'expired' || ($job['expires_at'] !== null && strtotime((string) $job['expires_at']) <= time())) {
            throw new ApiException(410, 'EXPORT_EXPIRED', 'Die Exportdatei ist abgelaufen.');
        }
        if ($job['status'] !== 'complete' || !is_string($job['file_path'])
            || !is_file($job['file_path']) || !is_readable($job['file_path'])) {
            throw new ApiException(409, 'EXPORT_NOT_READY', 'Die Exportdatei ist noch nicht verfügbar.');
        }
        $this->audit->append('export.downloaded', (int) $user['id'], 'export_job', $publicId, ['sha256' => $job['sha256']]);

        return $job;
    }

    /** @return array<string, mixed> */
    private function normalize(array $job): array
    {
        unset($job['file_path'], $job['audit_head_hash']);
        if (isset($job['id'])) {
            $job['id'] = (int) $job['id'];
        }
        if (isset($job['requested_by_user_id'])) {
            $job['requested_by_user_id'] = (int) $job['requested_by_user_id'];
        }
        if (isset($job['attempt_count'])) {
            $job['attempt_count'] = (int) $job['attempt_count'];
        }
        if (isset($job['file_size'])) {
            $job['file_size'] = $job['file_size'] === null ? null : (int) $job['file_size'];
        }
        $job['draft'] = (bool) ($job['draft'] ?? false);
        $job['parameters'] = isset($job['parameters_json']) ? json_decode((string) $job['parameters_json'], true) : null;
        unset($job['parameters_json']);

        return $job;
    }

    private function date(mixed $value, bool $end): \DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new ApiException(422, 'INVALID_EXPORT_REQUEST', 'Von- und Bis-Datum müssen im Format YYYY-MM-DD vorliegen.');
        }
        $suffix = $end ? 'T23:59:59Z' : 'T00:00:00Z';
        try {
            return new \DateTimeImmutable($value . $suffix, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new ApiException(422, 'INVALID_EXPORT_REQUEST', 'Das Exportdatum ist ungültig.');
        }
    }

    /** @return list<int> */
    private function integers(mixed $values): array
    {
        if (!is_array($values) || count($values) > 100 || array_filter($values, static fn ($value): bool => !is_int($value) || $value < 1) !== []) {
            throw new ApiException(422, 'INVALID_EXPORT_REQUEST', 'Die Messstellenauswahl ist ungültig.');
        }

        return array_values(array_unique($values));
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (!is_array($values) || count($values) > 100 || array_filter($values, static fn ($value): bool => !is_string($value) || mb_strlen($value) > 80) !== []) {
            throw new ApiException(422, 'INVALID_EXPORT_REQUEST', 'Eine Exportauswahl ist ungültig.');
        }

        return array_values(array_unique($values));
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
