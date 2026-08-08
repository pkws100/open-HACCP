<?php

declare(strict_types=1);

namespace Haccp\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Haccp\Demo\DemoHttpClient;
use Haccp\Demo\DemoPayloadFactory;
use Haccp\Demo\DemoProfileCatalog;
use Haccp\Demo\DemoStateStore;
use PHPUnit\Framework\TestCase;

final class DemoFleetTest extends TestCase
{
    public function testCatalogDefinesThreeDistinctExpectedProfiles(): void
    {
        $profiles = DemoProfileCatalog::all();

        self::assertCount(3, $profiles);
        self::assertSame(3, count(array_unique(array_column($profiles, 'device_uid'))));
        self::assertSame(['Kühlschrank', 'Gefriertruhe', 'Getränkekühler – Milchgetränke'], array_column($profiles, 'name'));
        self::assertSame([6200, 5800, 5400], array_column($profiles, 'battery_mv'));
        self::assertSame([-50, -66, -82], array_column($profiles, 'rssi_dbm'));
    }

    public function testPayloadFactoryCreatesHistoryThenSingleMeasurementAndValidatesCorrelatedAcks(): void
    {
        $factory = new DemoPayloadFactory();
        $profile = DemoProfileCatalog::all()[0];
        $sentAt = new DateTimeImmutable('2026-08-08T12:00:00Z', new DateTimeZone('UTC'));
        $batch = $factory->batch($profile, 1, 12, 1, 1, $sentAt);

        self::assertCount(12, $batch['measurements']);
        self::assertSame('2026-08-08T11:00:00Z', $batch['measurements'][0]['measured_at']);
        self::assertSame('2026-08-08T11:55:00Z', $batch['measurements'][11]['measured_at']);
        self::assertCount(1, $factory->batch($profile, 13, 1, 1, 2, $sentAt)['measurements']);

        $acknowledgements = [];
        foreach ($batch['measurements'] as $index => $measurement) {
            $acknowledgements[] = [
                'index' => $index,
                'measurement_point' => $measurement['measurement_point'],
                'sequence' => $measurement['sequence'],
                'status' => $index % 2 === 0 ? 'accepted' : 'duplicate',
            ];
        }
        $response = ['success' => true, 'batch_id' => $batch['batch_id'], 'acknowledgements' => $acknowledgements];
        self::assertTrue($factory->isAcknowledged($batch, $response));
        $response['acknowledgements'][0]['sequence'] = 999;
        self::assertFalse($factory->isAcknowledged($batch, $response));
    }

    public function testStateStorePersistsSequencePendingBatchAndSecretWithRestrictedMode(): void
    {
        $directory = sys_get_temp_dir() . '/haccp-demo-' . bin2hex(random_bytes(6));
        $path = $directory . '/state.json';
        $store = new DemoStateStore($path);
        $state = [
            'schema_version' => 1,
            'devices' => ['haccp-demo-fridge' => [
                'key' => str_repeat('a', 64),
                'sequence' => 12,
                'pending_batch' => ['batch_id' => 'retry-me'],
            ]],
        ];

        $store->save($state);
        self::assertSame($state, $store->load());
        self::assertSame(0600, fileperms($path) & 0777);

        unlink($path);
        rmdir($directory);
    }

    public function testHttpsConnectionFailureReturnsSafeEmptyResponse(): void
    {
        $response = (new DemoHttpClient('https://127.0.0.1:1'))->post(
            '/api/v1/device/heartbeat',
            'haccp-demo-fridge',
            str_repeat('a', 64),
            ['protocol_version' => 1],
        );

        self::assertSame(0, $response['status']);
        self::assertNull($response['json']);
    }
}
