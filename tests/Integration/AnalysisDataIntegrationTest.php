<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Haccp\Repository\EventRepository;

final class AnalysisDataIntegrationTest extends IntegrationTestCase
{
    public function testLongAnalysisSamplesTheWholePeriodAndKeepsRecentAndExtremeReadings(): void
    {
        $deviceId = (int) $this->pdo->query("SELECT id FROM devices WHERE device_uid = 'haccp-test-0001'")->fetchColumn();
        $pointId = (int) $this->pdo->query("SELECT id FROM measurement_points WHERE code = 'fridge-1'")->fetchColumn();
        $start = new DateTimeImmutable('-25 days', new DateTimeZone('UTC'));
        $latest = new DateTimeImmutable('-10 minutes', new DateTimeZone('UTC'));
        $insert = $this->pdo->prepare(
            'INSERT INTO measurements
             (device_id, measurement_point_id, sequence, measured_at, received_at,
              temperature_c, humidity_rh, battery_mv, created_at)
             VALUES (:device_id, :point_id, :sequence, :measured_at, :received_at,
                     :temperature_c, :humidity_rh, NULL, :created_at)',
        );
        $this->pdo->beginTransaction();
        for ($index = 0; $index < 3001; $index++) {
            $timestamp = $start->modify(sprintf('+%d minutes', $index * 10))->format('Y-m-d H:i:s.u');
            $insert->execute([
                'device_id' => $deviceId,
                'point_id' => $pointId,
                'sequence' => $index + 1,
                'measured_at' => $timestamp,
                'received_at' => $timestamp,
                'temperature_c' => $index === 1500 ? -30 : 4,
                'humidity_rh' => $index === 1800 ? 99 : 60,
                'created_at' => $timestamp,
            ]);
        }
        $latestAt = $latest->format('Y-m-d H:i:s.u');
        $insert->execute([
            'device_id' => $deviceId,
            'point_id' => $pointId,
            'sequence' => 3002,
            'measured_at' => $latestAt,
            'received_at' => $latestAt,
            'temperature_c' => 5,
            'humidity_rh' => 61,
            'created_at' => $latestAt,
        ]);
        $this->pdo->commit();

        $analysis = $this->json($this->dashboardRequest('/api/v1/dashboard/analysis?days=30&device=' . $this->deviceUid));
        $sample = $analysis['measurements'];

        self::assertSame(3002, $analysis['fleet']['measurements']);
        self::assertSame(3002, $analysis['measurements_sampling']['total_count']);
        self::assertTrue($analysis['measurements_sampling']['sampled']);
        self::assertSame('per_point_time_bucket_extrema', $analysis['measurements_sampling']['method']);
        self::assertSame(count($sample), $analysis['measurements_sampling']['returned_count']);
        self::assertLessThan(2600, count($sample));
        self::assertSame($start->format('Y-m-d H:i:s.u'), $sample[0]['measured_at']);
        self::assertSame($latestAt, $sample[array_key_last($sample)]['measured_at']);
        self::assertEquals(-30.0, min(array_column($sample, 'temperature_c')));
        self::assertEquals(99.0, max(array_column($sample, 'humidity_rh')));
        self::assertSame($this->deviceUid, $sample[array_key_last($sample)]['device_uid']);
        self::assertSame('fridge-1', $sample[array_key_last($sample)]['point_code']);
        self::assertLessThanOrEqual(1000, count($analysis['battery']['series']));
    }

    public function testEventChartFollowsPointFilterWhileConnectionsAndAvailabilityRemainDeviceWide(): void
    {
        $deviceId = (int) $this->pdo->query("SELECT id FROM devices WHERE device_uid = 'haccp-test-0001'")->fetchColumn();
        $pointId = (int) $this->pdo->query("SELECT id FROM measurement_points WHERE code = 'fridge-1'")->fetchColumn();
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $events = new EventRepository($this->pdo);
        foreach ([$pointId, null] as $eventPoint) {
            $events->create([
                'device_id' => $deviceId,
                'measurement_point_id' => $eventPoint,
                'event_type' => $eventPoint === null ? 'device_offline' : 'temperature_above_max',
                'severity' => 'warning',
                'state' => 'open',
                'opened_at' => $now,
                'threshold_min' => null,
                'threshold_max' => null,
                'observed_value' => null,
                'source_measurement_id' => null,
                'source_transmission_id' => null,
                'metadata_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $filtered = $this->json($this->dashboardRequest(
            '/api/v1/dashboard/analysis?days=7&device=' . $this->deviceUid . '&measurement_point_id=' . $pointId,
        ));
        $device = $this->json($this->dashboardRequest('/api/v1/dashboard/analysis?days=7&device=' . $this->deviceUid));

        self::assertSame('measurement_point', $filtered['scopes']['events']);
        self::assertSame('device', $filtered['scopes']['connections']);
        self::assertSame('device', $filtered['scopes']['availability']);
        self::assertSame('current', $filtered['scopes']['open_events']);
        self::assertCount(1, $filtered['events_by_day']);
        self::assertSame('temperature_above_max', $filtered['events_by_day'][0]['event_type']);
        self::assertCount(2, $device['events_by_day']);
        self::assertSame(1, $filtered['fleet']['open_events']);
    }
}
