<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;

final class UnmonitoredBatteryIntegrationTest extends IntegrationTestCase
{
    public function testBatteryPoweredDeviceWithoutVoltageReadingKeepsMeasurementsAndClosesLowAlarm(): void
    {
        $this->pdo->exec("UPDATE measurement_points SET sensor_type = 'DHT22' WHERE code = 'fridge-1'");
        $heartbeat = [
            'protocol_version' => 1,
            'firmware_version' => '0.2.0',
            'hardware_revision' => 'esp32-wroom-dht22',
            'battery_mv' => 5300,
            'rssi_dbm' => -60,
            'wifi_connect_ms' => 500,
            'boot_count' => 1,
        ];
        self::assertSame(200, $this->request('POST', '/api/v1/device/heartbeat', $heartbeat)->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'battery_low' AND closed_at IS NULL")->fetchColumn());

        $earlier = $this->batch([$this->measurement(1, new DateTimeImmutable('-2 minutes', new DateTimeZone('UTC')))], 'measured-battery');
        $earlier['diagnostics']['battery_mv'] = 5300;
        $earlier['measurements'][0]['battery_mv'] = 5300;
        self::assertSame(200, $this->request('POST', '/api/v1/device/measurements', $earlier)->getStatusCode());

        $heartbeat['battery_mv'] = null;
        $heartbeat['boot_count'] = 2;
        $heartbeat['device_info'] = [
            'board_model' => 'ESP32 ESP-WROOM-32', 'chip_model' => 'ESP32-D0WD-V3', 'chip_revision' => 3,
            'cpu_cores' => 2, 'flash_bytes' => 4194304, 'psram_bytes' => 0,
            'heap_free_bytes' => 150000, 'sensor_model' => 'DHT22', 'sensor_status' => 'ready',
            'queue_capacity' => 64,
            'capabilities' => ['temperature', 'humidity', 'battery_power_unmonitored', 'remote_config'],
        ];
        $response = $this->request('POST', '/api/v1/device/heartbeat', $heartbeat);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'battery_low' AND closed_at IS NULL")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT last_battery_mv FROM devices WHERE device_uid = 'haccp-test-0001'")->fetchColumn());

        $batch = $this->batch([$this->measurement(2, new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')))], 'unmonitored-battery');
        $batch['diagnostics']['battery_mv'] = null;
        $batch['measurements'][0]['battery_mv'] = null;
        $batch['device_info'] = $heartbeat['device_info'];
        $first = $this->request('POST', '/api/v1/device/measurements', $batch);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame('accepted', $this->json($first)['acknowledgements'][0]['status']);
        $repeat = $this->request('POST', '/api/v1/device/measurements', $batch);
        self::assertSame(200, $repeat->getStatusCode(), (string) $repeat->getBody());
        self::assertSame('duplicate', $this->json($repeat)['acknowledgements'][0]['status']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM measurements')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT battery_mv FROM measurements WHERE sequence = 2')->fetchColumn());
        self::assertSame(5300, (int) $this->pdo->query('SELECT battery_mv FROM measurements WHERE sequence = 1')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT battery_mv FROM device_transmissions ORDER BY id DESC LIMIT 1')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'battery_low' AND closed_at IS NULL")->fetchColumn());

        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertSame('battery_unmonitored', $overview['selected_device']['battery']['power_source']);
        self::assertSame('unknown', $overview['selected_device']['battery']['state']);
        self::assertNull($overview['selected_device']['battery']['millivolts']);
        self::assertSame('battery_unmonitored', $overview['devices'][0]['battery']['power_source']);
        self::assertSame('DHT22', $overview['selected_device']['device_info']['sensor_model']);
        self::assertNull($overview['recent_measurements'][0]['battery_mv']);

        $analysis = $this->json($this->dashboardRequest('/api/v1/dashboard/analysis?days=7&device=' . $this->deviceUid));
        self::assertSame('unavailable', $analysis['battery']['status']);
        self::assertSame([], $analysis['battery']['series']);
        self::assertCount(2, $analysis['measurements']);

        $heartbeat['battery_mv'] = 6100;
        $heartbeat['boot_count'] = 3;
        unset($heartbeat['device_info']);
        self::assertSame(200, $this->request('POST', '/api/v1/device/heartbeat', $heartbeat)->getStatusCode());
        $overview = $this->json($this->dashboardRequest('/api/v1/dashboard/overview'));
        self::assertSame('battery', $overview['selected_device']['battery']['power_source']);
        self::assertSame('full', $overview['selected_device']['battery']['state']);
        self::assertSame(6100, $overview['selected_device']['battery']['millivolts']);
    }
}
