<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

use Haccp\Repository\DeviceRepository;
use Haccp\Service\ApiKeyService;
use Haccp\Support\Clock;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class ApiIntegrationTest extends IntegrationTestCase
{
    public function testHealthcheckReturns200(): void
    {
        $response = $this->request('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $this->json($response)['status']);
    }

    public function testValidDeviceKeyAuthenticates(): void
    {
        $response = $this->request('GET', '/api/v1/device/config');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->json($response)['protocol_version']);
    }

    public function testInvalidDeviceKeyReturns401(): void
    {
        $response = $this->request('GET', '/api/v1/device/config', null, str_repeat('b', 64));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('DEVICE_AUTHENTICATION_FAILED', $this->json($response)['error']['code']);
    }

    public function testValidBatchCreatesThreeMeasurementsAndAcknowledgements(): void
    {
        $response = $this->request('POST', '/api/v1/device/measurements', $this->batch());
        $json = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $json['result']['accepted']);
        self::assertSame(0, $json['result']['rejected']);
        self::assertCount(3, $json['acknowledgements']);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM measurements')->fetchColumn());
    }

    public function testRepeatedBatchCreatesNoDuplicatesInDatabase(): void
    {
        $batch = $this->batch();
        $this->request('POST', '/api/v1/device/measurements', $batch);
        $json = $this->json($this->request('POST', '/api/v1/device/measurements', $batch));

        self::assertSame(0, $json['result']['accepted']);
        self::assertSame(3, $json['result']['duplicates']);
        self::assertSame(['duplicate', 'duplicate', 'duplicate'], array_column($json['acknowledgements'], 'status'));
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM measurements')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM device_transmissions')->fetchColumn());
    }

    public function testBatchPartiallyAcceptsTwoValidAndRejectsOneInvalidMeasurement(): void
    {
        $batch = $this->batch();
        $batch['measurements'][1]['humidity_rh'] = 100.1;
        $json = $this->json($this->request('POST', '/api/v1/device/measurements', $batch));

        self::assertTrue($json['success']);
        self::assertSame(2, $json['result']['accepted']);
        self::assertSame(1, $json['result']['rejected']);
        self::assertSame('INVALID_HUMIDITY', $json['rejections'][0]['code']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM measurements')->fetchColumn());
    }

    public function testUnknownMeasurementPointIsPartialRejection(): void
    {
        $sent = new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC'));
        $batch = $this->batch([$this->measurement(1, $sent, 'unknown-point')]);
        $response = $this->request('POST', '/api/v1/device/measurements', $batch);
        $json = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('UNKNOWN_MEASUREMENT_POINT', $json['rejections'][0]['code']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM measurements')->fetchColumn());
    }

    public function testConfigReturnsPrototypeDefaults(): void
    {
        $json = $this->json($this->request('GET', '/api/v1/device/config'));

        self::assertSame(300, $json['measurement']['interval_seconds']);
        self::assertSame(21600, $json['upload']['interval_seconds']);
        self::assertSame(500, $json['upload']['max_batch_size']);
        self::assertFalse($json['alarm']['enabled']);
    }

    public function testHeartbeatUpdatesDeviceDiagnostics(): void
    {
        $heartbeat = [
            'protocol_version' => 1,
            'firmware_version' => '0.2.0',
            'hardware_revision' => 'prototype-b',
            'battery_mv' => 6001,
            'rssi_dbm' => -61,
            'wifi_connect_ms' => 2050,
            'boot_count' => 77,
        ];
        $response = $this->request('POST', '/api/v1/device/heartbeat', $heartbeat);
        $row = $this->pdo->query(
            "SELECT last_seen_at, last_rssi_dbm, last_battery_mv, firmware_version, hardware_revision FROM devices WHERE device_uid = 'haccp-test-0001'",
        )->fetch();

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($row['last_seen_at']);
        self::assertSame(-61, (int) $row['last_rssi_dbm']);
        self::assertSame(6001, (int) $row['last_battery_mv']);
        self::assertSame('0.2.0', $row['firmware_version']);
        self::assertSame('prototype-b', $row['hardware_revision']);
    }

    public function testDeviceKeyNeverAppearsInApplicationLogs(): void
    {
        $this->request('POST', '/api/v1/device/measurements', $this->batch());
        $serializedLogs = json_encode($this->logger->records, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($this->deviceKey, $serializedLogs);
    }

    public function testSequenceConflictDoesNotOverwriteStoredMeasurement(): void
    {
        $batch = $this->batch();
        $this->request('POST', '/api/v1/device/measurements', $batch);
        $batch['batch_id'] = 'batch-conflict';
        $batch['measurements'][0]['temperature_c'] = 99.0;
        $json = $this->json($this->request('POST', '/api/v1/device/measurements', $batch));

        self::assertSame('SEQUENCE_CONFLICT', $json['rejections'][0]['code']);
        $stored = (float) $this->pdo->query('SELECT temperature_c FROM measurements WHERE sequence = 1')->fetchColumn();
        self::assertEqualsWithDelta(4.11, $stored, 0.001);
    }

    public function testReportsSequenceGap(): void
    {
        $sent = new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC'));
        $this->request('POST', '/api/v1/device/measurements', $this->batch([$this->measurement(1, $sent)], 'batch-1'));
        $json = $this->json($this->request(
            'POST',
            '/api/v1/device/measurements',
            $this->batch([$this->measurement(4, $sent)], 'batch-2'),
        ));

        self::assertSame(2, $json['sequence_gaps'][0]['from_sequence']);
        self::assertSame(3, $json['sequence_gaps'][0]['to_sequence']);
    }

    public function testMeasurementExtensionsAreRejectedButMetadataExtensionsAreAccepted(): void
    {
        $batch = $this->batch();
        $batch['future_metadata'] = ['value' => true];
        $batch['diagnostics']['future_metric'] = 1;
        $batch['measurements'][0]['typo_field'] = 1;
        $json = $this->json($this->request('POST', '/api/v1/device/measurements', $batch));

        self::assertSame(2, $json['result']['accepted']);
        self::assertSame('UNKNOWN_MEASUREMENT_FIELD', $json['rejections'][0]['code']);
    }

    public function testKeyRotationAndDisableTakeEffectImmediately(): void
    {
        $repository = new DeviceRepository($this->pdo);
        $device = $repository->findByUid($this->deviceUid);
        self::assertNotNull($device);
        $newKey = str_repeat('c', 64);
        $now = (new Clock())->database((new Clock())->now());
        $repository->updateApiKey($device->id, (new ApiKeyService($this->config->deviceKeyPepper))->hash($newKey), $now);

        self::assertSame(401, $this->request('GET', '/api/v1/device/config')->getStatusCode());
        self::assertSame(200, $this->request('GET', '/api/v1/device/config', null, $newKey)->getStatusCode());

        $repository->disable($this->deviceUid, $now);
        self::assertSame(401, $this->request('GET', '/api/v1/device/config', null, $newKey)->getStatusCode());
    }

    public function testPayloadOver256KiBReturns413(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/device/heartbeat')
            ->withHeader('X-Device-ID', $this->deviceUid)
            ->withHeader('X-Device-Key', $this->deviceKey)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(str_repeat('x', 262145)));
        $response = $this->app->handle($request);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('PAYLOAD_TOO_LARGE', $this->json($response)['error']['code']);
    }

    public function testAllMigrationTablesExist(): void
    {
        $statement = $this->pdo->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN
             ('devices', 'measurement_points', 'measurements', 'device_transmissions', 'device_configs')",
        );

        self::assertCount(5, $statement->fetchAll());
    }

    public function testDashboardRequiresBasicAuthentication(): void
    {
        $response = $this->dashboardRequest('/dashboard', false);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('DASHBOARD_AUTHENTICATION_REQUIRED', $this->json($response)['error']['code']);
        self::assertStringContainsString('Basic realm=', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testDashboardRendersAndOverviewReturnsStoredMeasurements(): void
    {
        $html = $this->dashboardRequest('/dashboard');
        self::assertSame(200, $html->getStatusCode());
        self::assertStringContainsString('Open HACCP Monitor', (string) $html->getBody());

        $this->request('POST', '/api/v1/device/measurements', $this->batch());
        $overview = $this->dashboardRequest('/api/v1/dashboard/overview?hours=24');
        $json = $this->json($overview);

        self::assertSame(200, $overview->getStatusCode());
        self::assertSame($this->deviceUid, $json['selection']['device_uid']);
        self::assertSame('fridge-1', $json['selection']['measurement_point']);
        self::assertSame(3, $json['kpis']['measurement_count']);
        self::assertCount(3, $json['series']);
        self::assertCount(3, $json['recent_measurements']);
    }
}
