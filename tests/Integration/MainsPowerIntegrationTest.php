<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

final class MainsPowerIntegrationTest extends IntegrationTestCase
{
    public function testMainsDeviceAcceptsNullBatteryWithoutAlarmOrInventedForecast(): void
    {
        $this->pdo->exec("UPDATE measurement_points SET sensor_type = 'DHT22' WHERE code = 'fridge-1'");
        $heartbeat = [
            'protocol_version' => 1,
            'firmware_version' => '0.2.0',
            'hardware_revision' => 'd1-mini-dht22',
            'battery_mv' => 5300,
            'rssi_dbm' => -60,
            'wifi_connect_ms' => 500,
            'boot_count' => 1,
        ];
        self::assertSame(200, $this->request('POST', '/api/v1/device/heartbeat', $heartbeat)->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'battery_low' AND closed_at IS NULL")->fetchColumn());

        $heartbeat['battery_mv'] = null;
        $heartbeat['device_info'] = [
            'board_model' => 'ESP8266 D1 mini ESP8266MOD', 'chip_model' => 'ESP8266', 'chip_revision' => 0,
            'cpu_cores' => 1, 'flash_bytes' => 4194304, 'psram_bytes' => 0,
            'heap_free_bytes' => 32000, 'sensor_model' => 'DHT22', 'sensor_status' => 'ready',
            'queue_capacity' => 32, 'capabilities' => ['temperature', 'humidity', 'mains_power', 'remote_config'],
        ];
        self::assertSame(200, $this->request('POST', '/api/v1/device/heartbeat', $heartbeat)->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'battery_low' AND closed_at IS NULL")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT last_battery_mv FROM devices WHERE device_uid = 'haccp-test-0001'")->fetchColumn());

        $batch = $this->batch([$this->measurement(1, new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))]);
        $batch['diagnostics']['battery_mv'] = null;
        $batch['measurements'][0]['battery_mv'] = null;
        $batch['device_info'] = $heartbeat['device_info'];
        $first = $this->request('POST', '/api/v1/device/measurements', $batch);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame('accepted', $this->json($first)['acknowledgements'][0]['status']);
        $repeat = $this->request('POST', '/api/v1/device/measurements', $batch);
        self::assertSame(200, $repeat->getStatusCode());
        self::assertSame('duplicate', $this->json($repeat)['acknowledgements'][0]['status']);
        self::assertNull($this->pdo->query('SELECT battery_mv FROM measurements LIMIT 1')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT battery_mv FROM device_transmissions ORDER BY id DESC LIMIT 1')->fetchColumn());

        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertSame('mains', $overview['selected_device']['battery']['state']);
        self::assertSame('mains', $overview['selected_device']['battery']['power_source']);
        self::assertNull($overview['selected_device']['battery']['millivolts']);
        self::assertSame('DHT22', $overview['selected_device']['device_info']['sensor_model']);
        self::assertSame('ESP8266 D1 mini ESP8266MOD', $overview['selected_device']['device_info']['board_model']);
        self::assertSame('DHT22', $overview['selected_measurement_point']['sensor_type']);
        self::assertNull($overview['recent_measurements'][0]['battery_mv']);

        $analysis = $this->json($this->dashboardRequest('/api/v1/dashboard/analysis?days=7&device=' . $this->deviceUid));
        self::assertSame('unavailable', $analysis['battery']['status']);
        self::assertNull($analysis['measurements'][0]['battery_mv']);
    }
}
