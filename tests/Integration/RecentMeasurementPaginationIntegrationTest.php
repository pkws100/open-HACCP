<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Support\Clock;

final class RecentMeasurementPaginationIntegrationTest extends IntegrationTestCase
{
    public function testRecentPagesAreBoundedAndStableWhileNewMeasurementsArrive(): void
    {
        $measuredAt = new DateTimeImmutable('-10 minutes', new DateTimeZone('UTC'));
        $measurements = [];
        for ($sequence = 1; $sequence <= 53; $sequence++) {
            $measurements[] = $this->measurement($sequence, $measuredAt);
        }
        $ingest = $this->json($this->request('POST', '/api/v1/device/measurements', $this->batch($measurements)));
        self::assertSame(53, $ingest['result']['accepted']);

        $first = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertSame(range(53, 29), array_column($first['recent_measurements'], 'sequence'));
        self::assertSame(25, $first['recent_pagination']['per_page']);
        self::assertSame(53, $first['recent_pagination']['total']);
        self::assertSame(3, $first['recent_pagination']['total_pages']);
        self::assertSame(1, $first['recent_pagination']['page']);
        self::assertFalse($first['recent_pagination']['has_previous']);
        self::assertTrue($first['recent_pagination']['has_next']);
        $snapshot = $first['recent_pagination']['snapshot_id'];

        $base = '/api/v1/dashboard/overview?recent_only=1&device=' . $this->deviceUid
            . '&point=fridge-1&recent_snapshot_id=' . $snapshot;
        $second = $this->json($this->dashboardRequest($base . '&recent_page=2'));
        self::assertSame(['selection', 'recent_measurements', 'recent_pagination'], array_keys($second));
        self::assertSame(range(28, 4), array_column($second['recent_measurements'], 'sequence'));
        self::assertSame(2, $second['recent_pagination']['page']);
        self::assertTrue($second['recent_pagination']['has_previous']);
        self::assertTrue($second['recent_pagination']['has_next']);
        self::assertSame($snapshot, $second['recent_pagination']['snapshot_id']);

        $last = $this->json($this->dashboardRequest($base . '&recent_page=3'));
        self::assertSame([3, 2, 1], array_column($last['recent_measurements'], 'sequence'));
        self::assertFalse($last['recent_pagination']['has_next']);
        self::assertSame(3, $last['recent_pagination']['page']);

        $beyondEnd = $this->json($this->dashboardRequest($base . '&recent_page=999999999'));
        self::assertSame(3, $beyondEnd['recent_pagination']['page']);
        self::assertSame([3, 2, 1], array_column($beyondEnd['recent_measurements'], 'sequence'));
        $invalidPage = $this->json($this->dashboardRequest($base . '&recent_page=-1'));
        self::assertSame(1, $invalidPage['recent_pagination']['page']);

        $newBatch = $this->batch([$this->measurement(54, $measuredAt)], 'new-measurement');
        self::assertSame(1, $this->json($this->request('POST', '/api/v1/device/measurements', $newBatch))['result']['accepted']);
        $stableSecond = $this->json($this->dashboardRequest($base . '&recent_page=2'));
        self::assertSame(range(28, 4), array_column($stableSecond['recent_measurements'], 'sequence'));
        self::assertSame(53, $stableSecond['recent_pagination']['total']);

        $fresh = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertSame(54, $fresh['recent_pagination']['total']);
        self::assertSame(54, $fresh['recent_measurements'][0]['sequence']);
    }

    public function testRecentPageFollowsMeasurementPointAndHandlesEmptyData(): void
    {
        $empty = $this->json($this->dashboardRequest('/api/v1/dashboard/overview?recent_only=1'));
        self::assertSame([], $empty['recent_measurements']);
        self::assertSame(0, $empty['recent_pagination']['total']);
        self::assertSame(0, $empty['recent_pagination']['total_pages']);
        self::assertSame(1, $empty['recent_pagination']['page']);

        $deviceId = (int) $this->pdo->query('SELECT id FROM devices LIMIT 1')->fetchColumn();
        $now = (new Clock())->database((new Clock())->now());
        (new MeasurementPointRepository($this->pdo))->create([
            'device_id' => $deviceId,
            'code' => 'freezer-2',
            'name' => 'Freezer',
            'sensor_type' => 'DHT22',
            'location' => 'Test kitchen',
            'temperature_min_c' => null,
            'temperature_max_c' => null,
            'humidity_min_rh' => null,
            'humidity_max_rh' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $at = new DateTimeImmutable('-10 minutes', new DateTimeZone('UTC'));
        $batch = $this->batch([
            $this->measurement(1, $at, 'fridge-1'),
            $this->measurement(1, $at, 'freezer-2'),
            $this->measurement(2, $at, 'freezer-2'),
        ]);
        self::assertSame(3, $this->json($this->request('POST', '/api/v1/device/measurements', $batch))['result']['accepted']);

        $url = '/api/v1/dashboard/overview?recent_only=1&device=' . $this->deviceUid . '&point=freezer-2';
        $freezer = $this->json($this->dashboardRequest($url));
        self::assertSame('freezer-2', $freezer['selection']['measurement_point']);
        self::assertSame([2, 1], array_column($freezer['recent_measurements'], 'sequence'));
        self::assertSame(2, $freezer['recent_pagination']['total']);

        $fridge = $this->json($this->dashboardRequest('/api/v1/dashboard/overview?recent_only=1&device='
            . $this->deviceUid . '&point=fridge-1'));
        self::assertSame([1], array_column($fridge['recent_measurements'], 'sequence'));
        self::assertSame(1, $fridge['recent_pagination']['total']);
    }
}
