<?php

declare(strict_types=1);

namespace Haccp\Service;

use DateTimeZone;
use Haccp\Api\ApiException;
use Haccp\Repository\AuthRepository;
use Haccp\Repository\ComplianceRepository;
use Haccp\Support\Clock;
use PDO;
use Throwable;

final readonly class ComplianceService
{
    public function __construct(
        private PDO $pdo,
        private ComplianceRepository $repository,
        private AuthRepository $users,
        private AuditService $audit,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        return ['establishment' => $this->normalizeEstablishment($this->repository->establishment()), 'measurement_points' => $this->repository->pointsWithCurrentConfig()];
    }

    /** @return array<string, mixed> */
    public function updateEstablishment(object $payload, int $actorId): array
    {
        $required = ['legal_name', 'address_line1', 'postal_code', 'city'];
        $data = [];
        foreach ($required as $field) {
            $value = trim(is_string($payload->{$field} ?? null) ? $payload->{$field} : '');
            if ($value === '' || mb_strlen($value) > 200) {
                throw new ApiException(422, 'ESTABLISHMENT_VALIDATION_FAILED', 'Pflichtangaben zum Betrieb fehlen.', ['field' => $field]);
            }
            $data[$field] = $value;
        }
        $data['trade_name'] = $this->nullableText($payload->trade_name ?? null, 200);
        $data['address_line2'] = $this->nullableText($payload->address_line2 ?? null, 200);
        $data['authority_reference'] = $this->nullableText($payload->authority_reference ?? null, 160);
        $country = strtoupper(trim(is_string($payload->country_code ?? null) ? $payload->country_code : 'DE'));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new ApiException(422, 'ESTABLISHMENT_VALIDATION_FAILED', 'Der Ländercode ist ungültig.');
        }
        $timezone = is_string($payload->timezone ?? null) ? $payload->timezone : 'Europe/Berlin';
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new ApiException(422, 'ESTABLISHMENT_VALIDATION_FAILED', 'Die Zeitzone ist ungültig.');
        }
        $responsible = is_int($payload->haccp_responsible_user_id ?? null) ? $payload->haccp_responsible_user_id : null;
        if ($responsible !== null && $this->users->userById($responsible) === null) {
            throw new ApiException(422, 'ESTABLISHMENT_VALIDATION_FAILED', 'Die verantwortliche Person wurde nicht gefunden.');
        }
        $retention = is_int($payload->general_retention_months ?? null) ? $payload->general_retention_months : 24;
        if ($retention < 1 || $retention > 120) {
            throw new ApiException(422, 'ESTABLISHMENT_VALIDATION_FAILED', 'Die Aufbewahrung muss zwischen 1 und 120 Monaten liegen.');
        }
        $this->repository->updateEstablishment($data + [
            'country_code' => $country,
            'timezone' => $timezone,
            'haccp_responsible_user_id' => $responsible,
            'general_retention_months' => $retention,
            'updated_at' => $this->clock->database($this->clock->now()),
        ]);
        $this->audit->append('establishment.updated', $actorId, 'establishment', '1', ['country_code' => $country, 'timezone' => $timezone]);

        return $this->normalizeEstablishment($this->repository->establishment());
    }

    /** @return array<string, mixed> */
    public function updatePoint(int $pointId, object $payload, int $actorId): array
    {
        if ($this->repository->point($pointId) === null) {
            throw new ApiException(404, 'MEASUREMENT_POINT_NOT_FOUND', 'Die Messstelle wurde nicht gefunden.');
        }
        $expected = is_int($payload->expected_config_version ?? null) ? $payload->expected_config_version : 0;
        $legalProfile = is_string($payload->legal_profile ?? null) ? $payload->legal_profile : '';
        $classification = is_string($payload->control_classification ?? null) ? strtoupper($payload->control_classification) : '';
        $purpose = trim(is_string($payload->monitoring_purpose ?? null) ? $payload->monitoring_purpose : '');
        $retention = is_int($payload->retention_months ?? null) ? $payload->retention_months : 0;
        $responsible = is_int($payload->responsible_user_id ?? null) ? $payload->responsible_user_id : null;
        if (!in_array($legalProfile, ['general_haccp', 'quick_frozen'], true)
            || !in_array($classification, ['GHP', 'OPRP', 'CCP'], true)
            || $purpose === '' || mb_strlen($purpose) > 255 || $retention < 1 || $retention > 120
            || ($legalProfile === 'quick_frozen' && $retention < 12)
            || ($responsible !== null && $this->users->userById($responsible) === null)) {
            throw new ApiException(422, 'COMPLIANCE_PROFILE_INVALID', 'Das Compliance-Profil ist nicht plausibel.');
        }
        $conformity = is_string($payload->conformity_status ?? null) ? $payload->conformity_status : 'not_documented';
        if (!in_array($conformity, ['not_documented', 'documented', 'expired'], true)) {
            throw new ApiException(422, 'COMPLIANCE_PROFILE_INVALID', 'Der Konformitätsstatus ist ungültig.');
        }
        $now = $this->clock->database($this->clock->now());
        $this->pdo->beginTransaction();
        try {
            $current = $this->repository->currentPointConfig($pointId, true);
            $currentVersion = (int) ($current['config_version'] ?? 0);
            if ($expected !== $currentVersion) {
                throw new ApiException(409, 'COMPLIANCE_VERSION_CONFLICT', 'Das Compliance-Profil wurde zwischenzeitlich geändert.');
            }
            $version = $currentVersion + 1;
            $this->repository->insertPointConfig([
                'measurement_point_id' => $pointId,
                'config_version' => $version,
                'legal_profile' => $legalProfile,
                'control_classification' => $classification,
                'monitoring_purpose' => $purpose,
                'humidity_is_critical' => ($payload->humidity_is_critical ?? false) === true ? 1 : 0,
                'retention_months' => $retention,
                'responsible_user_id' => $responsible,
                'instrument_manufacturer' => $this->nullableText($payload->instrument_manufacturer ?? null, 160),
                'instrument_model' => $this->nullableText($payload->instrument_model ?? null, 160),
                'instrument_serial' => $this->nullableText($payload->instrument_serial ?? null, 160),
                'conformity_status' => $conformity,
                'conformity_reference' => $this->nullableText($payload->conformity_reference ?? null, 255),
                'calibration_reference' => $this->nullableText($payload->calibration_reference ?? null, 255),
                'verification_reference' => $this->nullableText($payload->verification_reference ?? null, 255),
                'calibrated_at' => $this->date($payload->calibrated_at ?? null),
                'verification_due_at' => $this->date($payload->verification_due_at ?? null),
                'effective_from' => $now,
                'created_by_user_id' => $actorId,
                'created_at' => $now,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->audit->append('compliance.updated', $actorId, 'measurement_point', (string) $pointId, ['version' => $version, 'profile' => $legalProfile]);

        return $this->repository->currentPointConfig($pointId) ?? [];
    }

    /** @return array{complete: bool, issues: list<string>} */
    public function preflight(array $pointIds = [], array $deviceUids = [], string $legalProfile = 'configured'): array
    {
        $establishment = $this->repository->establishment();
        $issues = [];
        foreach (['legal_name', 'address_line1', 'postal_code', 'city', 'haccp_responsible_user_id'] as $field) {
            if ($establishment[$field] === null || trim((string) $establishment[$field]) === '') {
                $issues[] = 'Betriebsangabe fehlt: ' . $field;
            }
        }
        foreach ($this->repository->pointsWithCurrentConfig() as $point) {
            if ($pointIds !== [] && !in_array((int) $point['id'], $pointIds, true)) {
                continue;
            }
            if ($deviceUids !== [] && !in_array((string) $point['device_uid'], $deviceUids, true)) {
                continue;
            }
            if ($point['config_version'] === null || $point['responsible_user_id'] === null) {
                $issues[] = 'Messstelle ohne vollständiges Profil: ' . $point['name'];
            }
            if (!(bool) $point['alarm_enabled'] || $point['temperature_min_c'] === null || $point['temperature_max_c'] === null) {
                $issues[] = 'Temperaturgrenzen fehlen oder sind deaktiviert: ' . $point['name'];
            }
            if ((int) $point['measurement_interval_seconds'] < 30 || (int) $point['upload_interval_seconds'] < 60) {
                $issues[] = 'Mess- oder Übertragungsintervall ist nicht plausibel: ' . $point['name'];
            }
            $quickFrozen = $legalProfile === 'quick_frozen'
                || ($legalProfile === 'configured' && $point['legal_profile'] === 'quick_frozen');
            if ($quickFrozen
                && ($point['conformity_status'] !== 'documented'
                    || empty($point['conformity_reference'])
                    || empty($point['calibration_reference'])
                    || empty($point['verification_reference'])
                    || (int) $point['retention_months'] < 12)) {
                $issues[] = 'Tiefkühlnachweis unvollständig: ' . $point['name'];
            }
        }

        return ['complete' => $issues === [], 'issues' => $issues];
    }

    private function normalizeEstablishment(array $row): array
    {
        if ($row !== []) {
            $row['id'] = (int) $row['id'];
            $row['haccp_responsible_user_id'] = $row['haccp_responsible_user_id'] === null ? null : (int) $row['haccp_responsible_user_id'];
            $row['general_retention_months'] = (int) $row['general_retention_months'];
        }

        return $row;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max) {
            throw new ApiException(422, 'COMPLIANCE_PROFILE_INVALID', 'Ein Textfeld ist zu lang oder ungültig.');
        }

        return trim($value);
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new ApiException(422, 'COMPLIANCE_PROFILE_INVALID', 'Ein Datum ist ungültig.');
        }

        return $value;
    }
}
