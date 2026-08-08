<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

use Haccp\Demo\DemoDeviceProvisioner;
use Haccp\Repository\DeviceConfigRepository;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\MeasurementPointRepository;
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
        $columns = $this->pdo->query(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE()
             AND table_name = 'device_configs' AND column_name IN ('battery_low_mv', 'battery_full_mv')",
        );
        self::assertCount(2, $columns->fetchAll());
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
        self::assertSame('full', $json['selected_device']['battery']['state']);
        self::assertSame(3, $json['selected_device']['wifi']['bars']);
        self::assertSame('disabled', $json['kpis']['alarm_status']);
        self::assertSame(1, $json['settings']['config_version']);
    }

    public function testDashboardDeviceProvisioningRequiresAuthenticationAndReturnsOneTimeSetupPackage(): void
    {
        self::assertSame(401, $this->dashboardRequest(
            '/api/v1/dashboard/devices',
            false,
            'POST',
            $this->provisioningPayload(),
        )->getStatusCode());

        $response = $this->dashboardRequest(
            '/api/v1/dashboard/devices',
            true,
            'POST',
            $this->provisioningPayload(),
        );
        $json = $this->json($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('haccp-provision-0001', $json['device']['device_uid']);
        self::assertSame('https://haccp.pow24.org', $json['setup_package']['api_base_url']);
        self::assertSame('counter-1', $json['setup_package']['measurement_point']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $json['setup_package']['device_key']);

        $stored = (string) $this->pdo->query(
            "SELECT api_key_hash FROM devices WHERE device_uid = 'haccp-provision-0001'",
        )->fetchColumn();
        self::assertNotSame($json['setup_package']['device_key'], $stored);
        self::assertTrue((new ApiKeyService($this->config->deviceKeyPepper))->verify($json['setup_package']['device_key'], $stored));
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM measurement_points mp JOIN devices d ON d.id = mp.device_id
             WHERE d.device_uid = 'haccp-provision-0001' AND mp.code = 'counter-1'",
        )->fetchColumn());
        $config = $this->pdo->query(
            "SELECT config_version, alarm_enabled, temperature_min_c, temperature_max_c, battery_low_mv, battery_full_mv
             FROM device_configs dc JOIN devices d ON d.id = dc.device_id WHERE d.device_uid = 'haccp-provision-0001'",
        )->fetch();
        self::assertSame(1, (int) $config['config_version']);
        self::assertSame(1, (int) $config['alarm_enabled']);
        self::assertEquals(2.0, (float) $config['temperature_min_c']);
        self::assertEquals(7.0, (float) $config['temperature_max_c']);
        self::assertSame(5600, (int) $config['battery_low_mv']);
        self::assertSame(6000, (int) $config['battery_full_mv']);
        self::assertStringNotContainsString(
            $json['setup_package']['device_key'],
            json_encode($this->logger->records, JSON_THROW_ON_ERROR),
        );
    }

    public function testDashboardDeviceProvisioningCanGenerateUidAndRejectsInvalidOrDuplicateData(): void
    {
        $generated = $this->provisioningPayload();
        unset($generated['device_uid']);
        $response = $this->dashboardRequest('/api/v1/dashboard/devices', true, 'POST', $generated);
        self::assertSame(201, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^haccp-[a-f0-9]{12}$/', $this->json($response)['device']['device_uid']);

        $invalid = $this->provisioningPayload();
        $invalid['measurement_point']['code'] = 'Nicht gültig';
        $invalid['alarm']['temperature_min_c'] = 8.0;
        $invalid['alarm']['temperature_max_c'] = 7.0;
        $invalidResponse = $this->dashboardRequest('/api/v1/dashboard/devices', true, 'POST', $invalid);
        self::assertSame(422, $invalidResponse->getStatusCode());
        self::assertSame('INVALID_DEVICE_PROVISIONING', $this->json($invalidResponse)['error']['code']);

        $payload = $this->provisioningPayload();
        self::assertSame(201, $this->dashboardRequest('/api/v1/dashboard/devices', true, 'POST', $payload)->getStatusCode());
        $duplicate = $this->dashboardRequest('/api/v1/dashboard/devices', true, 'POST', $payload);
        self::assertSame(409, $duplicate->getStatusCode());
        self::assertSame('DEVICE_UID_ALREADY_EXISTS', $this->json($duplicate)['error']['code']);
    }

    public function testDashboardSettingsRequireAuthenticationAndCreateVersionUsedByFirmware(): void
    {
        $payload = $this->settingsPayload();
        self::assertSame(401, $this->dashboardRequest(
            '/api/v1/dashboard/devices/' . $this->deviceUid . '/settings',
            false,
            'PUT',
            $payload,
        )->getStatusCode());

        $response = $this->dashboardRequest(
            '/api/v1/dashboard/devices/' . $this->deviceUid . '/settings',
            true,
            'PUT',
            $payload,
        );
        $json = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $json['config_version']);
        self::assertSame(5600, $json['settings']['battery']['low_threshold_mv']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM device_configs')->fetchColumn());

        $firmwareConfig = $this->json($this->request('GET', '/api/v1/device/config'));
        self::assertSame(2, $firmwareConfig['config_version']);
        self::assertTrue($firmwareConfig['alarm']['enabled']);
        self::assertEquals(2.0, $firmwareConfig['alarm']['temperature_min_c']);
        self::assertArrayNotHasKey('battery', $firmwareConfig);
    }

    public function testDashboardSettingsRejectInvalidRangesAndVersionConflicts(): void
    {
        $invalid = $this->settingsPayload();
        $invalid['alarm']['temperature_min_c'] = 8.0;
        $invalid['alarm']['temperature_max_c'] = 7.0;
        $invalid['battery']['low_threshold_mv'] = 6000;
        $invalid['battery']['full_threshold_mv'] = 6000;
        $response = $this->dashboardRequest(
            '/api/v1/dashboard/devices/' . $this->deviceUid . '/settings',
            true,
            'PUT',
            $invalid,
        );
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('INVALID_DEVICE_SETTINGS', $this->json($response)['error']['code']);

        $this->dashboardRequest(
            '/api/v1/dashboard/devices/' . $this->deviceUid . '/settings',
            true,
            'PUT',
            $this->settingsPayload(),
        );
        $conflict = $this->dashboardRequest(
            '/api/v1/dashboard/devices/' . $this->deviceUid . '/settings',
            true,
            'PUT',
            $this->settingsPayload(),
        );
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('DEVICE_CONFIG_VERSION_CONFLICT', $this->json($conflict)['error']['code']);
    }

    public function testDashboardSettingsRejectUnknownDevice(): void
    {
        $response = $this->dashboardRequest(
            '/api/v1/dashboard/devices/does-not-exist/settings',
            true,
            'PUT',
            $this->settingsPayload(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('DASHBOARD_DEVICE_NOT_FOUND', $this->json($response)['error']['code']);
    }

    public function testDashboardAlarmStatusUsesInclusiveLimitsAndShowsViolations(): void
    {
        $this->dashboardRequest(
            '/api/v1/dashboard/devices/' . $this->deviceUid . '/settings',
            true,
            'PUT',
            $this->settingsPayload(),
        );
        $sent = new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC'));
        $boundary = $this->measurement(1, $sent);
        $boundary['temperature_c'] = 7.0;
        $this->request('POST', '/api/v1/device/measurements', $this->batch([$boundary], 'boundary'));
        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertSame('normal', $overview['kpis']['alarm_status']);

        $above = $this->measurement(2, $sent);
        $above['temperature_c'] = 7.01;
        $this->request('POST', '/api/v1/device/measurements', $this->batch([$above], 'above'));
        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertSame('above_max', $overview['kpis']['alarm_status']);
        self::assertSame('above_max', $overview['devices'][0]['alarm']['state']);
    }

    public function testDisabledDevicesAreExcludedFromDashboard(): void
    {
        $repository = new DeviceRepository($this->pdo);
        $repository->disable($this->deviceUid, (new Clock())->database((new Clock())->now()));
        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));

        self::assertSame([], $overview['devices']);
        self::assertSame(0, $overview['fleet']['total_devices']);
        self::assertNull($overview['selection']);
    }

    public function testDemoProvisioningIsIdempotentRotatesOnlyReservedDevicesAndBuildsThreeDeviceOverview(): void
    {
        $devices = new DeviceRepository($this->pdo);
        $provisioner = new DemoDeviceProvisioner(
            $this->pdo,
            $devices,
            new MeasurementPointRepository($this->pdo),
            new DeviceConfigRepository($this->pdo),
            new ApiKeyService($this->config->deviceKeyPepper),
            new Clock(),
        );
        $initial = ['schema_version' => 1, 'devices' => []];
        $state = $provisioner->provision($initial);
        $counts = [
            'devices' => (int) $this->pdo->query("SELECT COUNT(*) FROM devices WHERE device_uid LIKE 'haccp-demo-%'")->fetchColumn(),
            'points' => (int) $this->pdo->query("SELECT COUNT(*) FROM measurement_points mp JOIN devices d ON d.id = mp.device_id WHERE d.device_uid LIKE 'haccp-demo-%'")->fetchColumn(),
            'configs' => (int) $this->pdo->query("SELECT COUNT(*) FROM device_configs dc JOIN devices d ON d.id = dc.device_id WHERE d.device_uid LIKE 'haccp-demo-%'")->fetchColumn(),
        ];
        $sameState = $provisioner->provision($state);

        self::assertSame(['devices' => 3, 'points' => 3, 'configs' => 6], $counts);
        self::assertSame($state, $sameState);
        self::assertSame(6, (int) $this->pdo->query("SELECT COUNT(*) FROM device_configs dc JOIN devices d ON d.id = dc.device_id WHERE d.device_uid LIKE 'haccp-demo-%'")->fetchColumn());
        foreach ($state['devices'] as $deviceState) {
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM devices WHERE api_key_hash = ' . $this->pdo->quote($deviceState['key']))->fetchColumn());
        }

        $oldHash = (string) $this->pdo->query("SELECT api_key_hash FROM devices WHERE device_uid = 'haccp-demo-fridge'")->fetchColumn();
        $rotated = $provisioner->provision($initial);
        $newHash = (string) $this->pdo->query("SELECT api_key_hash FROM devices WHERE device_uid = 'haccp-demo-fridge'")->fetchColumn();
        self::assertNotSame($oldHash, $newHash);
        self::assertNotSame($state['devices']['haccp-demo-fridge']['key'], $rotated['devices']['haccp-demo-fridge']['key']);

        $devices->disable($this->deviceUid, (new Clock())->database((new Clock())->now()));
        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertSame(3, $overview['fleet']['total_devices']);
        self::assertCount(3, $overview['devices']);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(): array
    {
        return [
            'expected_config_version' => 1,
            'alarm' => [
                'enabled' => true,
                'temperature_min_c' => 2.0,
                'temperature_max_c' => 7.0,
            ],
            'battery' => [
                'low_threshold_mv' => 5600,
                'full_threshold_mv' => 6000,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function provisioningPayload(): array
    {
        return [
            'device_uid' => 'haccp-provision-0001',
            'name' => 'Kühltheke Ausgabe',
            'measurement_point' => [
                'code' => 'counter-1',
                'name' => 'Kühltheke Innenraum',
                'sensor_type' => 'SHT45',
                'location' => 'Ausgabe',
            ],
            'alarm' => [
                'enabled' => true,
                'temperature_min_c' => 2.0,
                'temperature_max_c' => 7.0,
            ],
            'battery' => [
                'low_threshold_mv' => 5600,
                'full_threshold_mv' => 6000,
            ],
        ];
    }
}
