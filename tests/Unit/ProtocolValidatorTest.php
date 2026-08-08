<?php

declare(strict_types=1);

namespace Haccp\Tests\Unit;

use Haccp\Service\ProtocolValidator;
use Haccp\Support\Clock;
use PHPUnit\Framework\TestCase;

final class ProtocolValidatorTest extends TestCase
{
    private ProtocolValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ProtocolValidator(new Clock(), dirname(__DIR__, 2) . '/docs/protocol-v1.schema.json');
    }

    public function testRejectsUnknownMeasurementFieldsButAcceptsMetadataExtensions(): void
    {
        $sent = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $valid = (object) [
            'measurement_point' => 'fridge-1',
            'sequence' => 1,
            'measured_at' => $sent->modify('-1 minute')->format('Y-m-d\TH:i:s\Z'),
            'temperature_c' => 4.1,
            'humidity_rh' => 70.0,
            'battery_mv' => 6100,
        ];
        self::assertTrue($this->validator->validateMeasurement($valid, $sent)['valid']);

        $valid->typo = true;
        $result = $this->validator->validateMeasurement($valid, $sent);
        self::assertFalse($result['valid']);
        self::assertSame('UNKNOWN_MEASUREMENT_FIELD', $result['code']);

        $batch = $this->batch($sent);
        $batch->future_metadata = (object) ['supported' => true];
        $batch->diagnostics->future_diagnostic = 12;
        self::assertSame('batch-1', $this->validator->validateBatchEnvelope($batch)['batch_id']);
    }

    public function testRejectsHumidityOutsideTechnicalRange(): void
    {
        $sent = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $measurement = $this->batch($sent)->measurements[0];
        $measurement->humidity_rh = 100.1;
        $result = $this->validator->validateMeasurement($measurement, $sent);

        self::assertFalse($result['valid']);
        self::assertSame('INVALID_HUMIDITY', $result['code']);
    }

    public function testAcceptsStableDiagnosticCodesAndRejectsSecretLikeFreeText(): void
    {
        $sent = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $batch = $this->batch($sent);
        $batch->diagnostics->errors = ['RTC_SYNC_RETRIED', 'SENSOR.RECOVERED'];
        $validated = $this->validator->validateBatchEnvelope($batch);
        self::assertSame(['RTC_SYNC_RETRIED', 'SENSOR.RECOVERED'], $validated['diagnostics']['errors']);

        $batch->diagnostics->errors = ['WiFi password was invalid'];
        $this->expectException(\Haccp\Api\ApiException::class);
        $this->validator->validateBatchEnvelope($batch);
    }

    private function batch(\DateTimeImmutable $sent): \stdClass
    {
        return json_decode(json_encode([
            'protocol_version' => 1,
            'batch_id' => 'batch-1',
            'firmware_version' => '0.1.0',
            'hardware_revision' => 'prototype-a',
            'sent_at' => $sent->format('Y-m-d\TH:i:s\Z'),
            'diagnostics' => ['battery_mv' => 6100, 'rssi_dbm' => -55, 'wifi_connect_ms' => 1200, 'boot_count' => 1],
            'measurements' => [[
                'measurement_point' => 'fridge-1',
                'sequence' => 1,
                'measured_at' => $sent->modify('-1 minute')->format('Y-m-d\TH:i:s\Z'),
                'temperature_c' => 4.1,
                'humidity_rh' => 70.0,
                'battery_mv' => 6100,
            ]],
        ], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }
}
