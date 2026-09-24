<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Haccp\Repository\ExportRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Service\AuditService;
use Haccp\Support\Clock;

final class TemperatureCalibrationIntegrationTest extends IntegrationTestCase
{
    public function testOffsetsAreVersionedPerMeasurementTimeAndRawHistoryRemainsImmutable(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $first = $this->measurement(1, $now->modify('-60 seconds'));
        $first['temperature_c'] = 5.0;
        self::assertSame(1, $this->json($this->request('POST', '/api/v1/device/measurements', $this->batch([$first], 'calibration-initial')))['result']['accepted']);

        $positive = $this->settings(1, 3.0);
        self::assertSame(200, $this->dashboardRequest($this->settingsPath(), true, 'PUT', $positive)->getStatusCode());
        // Simulate elapsed time without delaying the test; queued samples span this effective boundary.
        $this->setEffectiveTime(2, $now->modify('-30 seconds'));

        $second = $this->measurement(2, $now->modify('-20 seconds'));
        $second['temperature_c'] = 5.0;
        self::assertSame(1, $this->json($this->request('POST', '/api/v1/device/measurements', $this->batch([$second], 'calibration-positive')))['result']['accepted']);
        $rows = $this->pdo->query('SELECT sequence, temperature_c, corrected_temperature_c, applied_temperature_offset_c, calibration_config_version FROM measurements ORDER BY sequence')->fetchAll();
        self::assertEquals(5.0, (float) $rows[0]['temperature_c']);
        self::assertEquals(5.0, (float) $rows[0]['corrected_temperature_c']);
        self::assertEquals(8.0, (float) $rows[1]['corrected_temperature_c']);
        self::assertEquals(3.0, (float) $rows[1]['applied_temperature_offset_c']);
        self::assertSame(2, (int) $rows[1]['calibration_config_version']);
        self::assertEquals(8.0, (float) $this->pdo->query("SELECT observed_value FROM compliance_events WHERE event_type = 'temperature_above_max' AND state = 'open'")->fetchColumn());

        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertEquals(8.0, $overview['kpis']['latest_temperature_c']);
        self::assertEquals(5.0, $overview['kpis']['latest_raw_temperature_c']);
        self::assertEquals(3.0, $overview['kpis']['latest_temperature_offset_c']);
        self::assertEquals(8.0, $overview['devices'][0]['latest_temperature_c']);
        self::assertSame('above_max', $overview['kpis']['alarm_status']);
        self::assertEquals(5.0, $overview['recent_measurements'][0]['raw_temperature_c']);
        self::assertEquals(3.0, $overview['recent_measurements'][0]['temperature_offset_c']);
        self::assertSame(2, $overview['recent_measurements'][0]['calibration_config_version']);

        $negative = $this->settings(2, -1.2);
        $saved = $this->json($this->dashboardRequest($this->settingsPath(), true, 'PUT', $negative));
        self::assertSame(3, $saved['config_version']);
        self::assertEquals(-1.2, $saved['settings']['calibration']['measurement_points'][0]['temperature_offset_c']);
        $this->setEffectiveTime(3, $now->modify('-10 seconds'));
        $third = $this->measurement(3, $now->modify('-5 seconds'));
        $third['temperature_c'] = 5.0;
        self::assertSame(1, $this->json($this->request('POST', '/api/v1/device/measurements', $this->batch([$third], 'calibration-negative')))['result']['accepted']);
        $row = $this->pdo->query('SELECT temperature_c, corrected_temperature_c, applied_temperature_offset_c, calibration_config_version FROM measurements WHERE sequence = 3')->fetch();
        self::assertEquals(5.0, (float) $row['temperature_c']);
        self::assertEquals(3.8, (float) $row['corrected_temperature_c']);
        self::assertEquals(-1.2, (float) $row['applied_temperature_offset_c']);
        self::assertSame(3, (int) $row['calibration_config_version']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'temperature_above_max' AND closed_at IS NULL")->fetchColumn());

        // An ACK retry must compare the wire-level raw value, never the corrected value.
        $duplicate = $this->json($this->request('POST', '/api/v1/device/measurements', $this->batch([$second], 'calibration-duplicate')));
        self::assertSame(1, $duplicate['result']['duplicates']);
        self::assertSame(0, $duplicate['result']['accepted']);
        self::assertEquals(8.0, (float) $this->pdo->query('SELECT corrected_temperature_c FROM measurements WHERE sequence = 2')->fetchColumn());

        $olderQueued = $this->measurement(4, $now->modify('-40 seconds'));
        $olderQueued['temperature_c'] = 5.0;
        $newerQueued = $this->measurement(5, $now->modify('-15 seconds'));
        $newerQueued['temperature_c'] = 5.0;
        $this->request('POST', '/api/v1/device/measurements', $this->batch([$olderQueued, $newerQueued], 'calibration-offline'));
        $queued = $this->pdo->query('SELECT sequence, corrected_temperature_c, calibration_config_version FROM measurements WHERE sequence IN (4, 5) ORDER BY sequence')->fetchAll();
        self::assertEquals(5.0, (float) $queued[0]['corrected_temperature_c']);
        self::assertSame(1, (int) $queued[0]['calibration_config_version']);
        self::assertEquals(8.0, (float) $queued[1]['corrected_temperature_c']);
        self::assertSame(2, (int) $queued[1]['calibration_config_version']);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'late_measurement_out_of_order'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'temperature_above_max' AND closed_at IS NULL")->fetchColumn());
        $lateEvent = $this->pdo->query("SELECT metadata_json FROM compliance_events WHERE event_type = 'late_measurement_out_of_order' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $lateMetadata = json_decode((string) $lateEvent, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('above_max', $lateMetadata['temperature_state']);
        self::assertEquals(8.0, $lateMetadata['corrected_temperature_c']);

        $analysis = $this->json($this->dashboardRequest('/api/v1/dashboard/analysis?days=7&device=' . $this->deviceUid));
        self::assertContains(3.8, array_column($analysis['measurements'], 'temperature_c'));
        $exportRows = (new ExportRepository($this->pdo))->measurements([
            'from_db' => $now->modify('-1 day')->format('Y-m-d H:i:s.u'),
            'to_db' => $now->modify('+1 day')->format('Y-m-d H:i:s.u'),
            'device_uids' => [$this->deviceUid],
            'measurement_point_ids' => [],
        ]);
        $exportThird = array_values(array_filter($exportRows, static fn (array $item): bool => (int) $item['sequence'] === 3))[0];
        self::assertEquals(3.8, (float) $exportThird['temperature_c']);
        self::assertEquals(5.0, (float) $exportThird['raw_temperature_c']);
        self::assertEquals(-1.2, (float) $exportThird['temperature_offset_c']);
        self::assertSame(3, (int) $exportThird['calibration_config_version']);
        self::assertTrue((new AuditService($this->pdo, new Clock(), $this->config->auditLogKey))->verify()['valid']);
    }

    public function testInvalidCalibrationIsRejectedAndLegacyRowsKeepRawMeaning(): void
    {
        $invalid = $this->settings(1, 10.001);
        $response = $this->dashboardRequest($this->settingsPath(), true, 'PUT', $invalid);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('INVALID_DEVICE_SETTINGS', $this->json($response)['error']['code']);
        self::assertSame(1, (int) $this->pdo->query('SELECT MAX(config_version) FROM device_configs')->fetchColumn());

        $unknown = $this->settings(1, 1.0);
        $unknown['calibration']['measurement_points'][0]['measurement_point'] = 'other-device-point';
        self::assertSame(422, $this->dashboardRequest($this->settingsPath(), true, 'PUT', $unknown)->getStatusCode());

        $measurement = $this->measurement(1, new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')));
        $measurement['temperature_c'] = 6.0;
        $this->request('POST', '/api/v1/device/measurements', $this->batch([$measurement], 'calibration-legacy'));
        $this->pdo->exec('UPDATE measurements SET corrected_temperature_c = NULL, applied_temperature_offset_c = NULL, calibration_config_version = NULL WHERE sequence = 1');
        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertEquals(6.0, $overview['kpis']['latest_temperature_c']);
        self::assertEquals(6.0, $overview['recent_measurements'][0]['raw_temperature_c']);
        self::assertEquals(0.0, $overview['recent_measurements'][0]['temperature_offset_c']);
        self::assertNull($overview['recent_measurements'][0]['calibration_config_version']);
    }

    public function testOffsetIsPerPointAndLegacySettingsWritesPreserveIt(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $deviceId = (int) $this->pdo->query('SELECT id FROM devices LIMIT 1')->fetchColumn();
        (new MeasurementPointRepository($this->pdo))->create([
            'device_id' => $deviceId,
            'code' => 'freezer-2',
            'name' => 'Second point',
            'sensor_type' => 'DHT22',
            'location' => null,
            'temperature_min_c' => null,
            'temperature_max_c' => null,
            'humidity_min_rh' => null,
            'humidity_max_rh' => null,
            'created_at' => $now->format('Y-m-d H:i:s.u'),
            'updated_at' => $now->format('Y-m-d H:i:s.u'),
        ]);
        self::assertSame(200, $this->dashboardRequest($this->settingsPath(), true, 'PUT', $this->settings(1, 1.2))->getStatusCode());
        $this->setEffectiveTime(2, $now->modify('-10 seconds'));
        $one = $this->measurement(1, $now->modify('-5 seconds'));
        $one['temperature_c'] = 5.0;
        $two = $this->measurement(1, $now->modify('-5 seconds'), 'freezer-2');
        $two['temperature_c'] = 5.0;
        self::assertSame(2, $this->json($this->request('POST', '/api/v1/device/measurements', $this->batch([$one, $two], 'calibration-two-points')))['result']['accepted']);
        $rows = $this->pdo->query('SELECT mp.code, m.corrected_temperature_c, m.applied_temperature_offset_c
            FROM measurements m INNER JOIN measurement_points mp ON mp.id = m.measurement_point_id ORDER BY mp.code')->fetchAll();
        self::assertSame('freezer-2', $rows[0]['code']);
        self::assertEquals(5.0, (float) $rows[0]['corrected_temperature_c']);
        self::assertEquals(0.0, (float) $rows[0]['applied_temperature_offset_c']);
        self::assertSame('fridge-1', $rows[1]['code']);
        self::assertEquals(6.2, (float) $rows[1]['corrected_temperature_c']);

        $legacyWrite = $this->settings(2, 0.0);
        unset($legacyWrite['calibration']);
        $saved = $this->json($this->dashboardRequest($this->settingsPath(), true, 'PUT', $legacyWrite));
        self::assertSame(3, $saved['config_version']);
        $offsets = array_column($saved['settings']['calibration']['measurement_points'], 'temperature_offset_c', 'measurement_point');
        self::assertEquals(1.2, $offsets['fridge-1']);
        self::assertEquals(0.0, $offsets['freezer-2']);
    }

    private function settings(int $version, float $offset): array
    {
        return [
            'expected_config_version' => $version,
            'alarm' => ['enabled' => true, 'temperature_min_c' => 0.0, 'temperature_max_c' => 7.0],
            'battery' => ['low_threshold_mv' => 5600, 'full_threshold_mv' => 6000],
            'calibration' => ['measurement_points' => [
                ['measurement_point' => 'fridge-1', 'temperature_offset_c' => $offset],
            ]],
        ];
    }

    private function settingsPath(): string
    {
        return '/api/v1/dashboard/devices/' . $this->deviceUid . '/settings';
    }

    private function setEffectiveTime(int $version, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare('UPDATE device_configs SET created_at = :effective WHERE config_version = :version');
        $statement->execute(['effective' => $at->format('Y-m-d H:i:s.u'), 'version' => $version]);
    }
}
