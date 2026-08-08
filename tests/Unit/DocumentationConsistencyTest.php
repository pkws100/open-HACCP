<?php

declare(strict_types=1);

namespace Haccp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DocumentationConsistencyTest extends TestCase
{
    public function testJsonSchemaAndOpenApiAreParseableAndExposeImplementedRoutes(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = json_decode((string) file_get_contents($root . '/docs/protocol-v1.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        $openApi = Yaml::parseFile($root . '/docs/openapi.yaml');

        self::assertSame(1, $schema['$defs']['batchEnvelope']['properties']['protocol_version']['const']);
        self::assertSame(500, $schema['$defs']['batchEnvelope']['properties']['measurements']['maxItems']);
        self::assertFalse($schema['$defs']['measurement']['additionalProperties']);
        self::assertArrayHasKey('/health', $openApi['paths']);
        self::assertArrayHasKey('/api/v1/device/measurements', $openApi['paths']);
        self::assertArrayHasKey('/api/v1/device/heartbeat', $openApi['paths']);
        self::assertArrayHasKey('/api/v1/device/config', $openApi['paths']);
        self::assertArrayHasKey('/api/v1/dashboard/overview', $openApi['paths']);
        self::assertArrayHasKey('/api/v1/dashboard/devices', $openApi['paths']);
        self::assertArrayHasKey('/api/v1/dashboard/devices/{device_uid}/settings', $openApi['paths']);
        self::assertSame('https://haccp.pow24.org', $openApi['servers'][0]['url']);
        self::assertStringContainsString('Only then mark that specific local record acknowledged', (string) file_get_contents($root . '/docs/FIRMWARE_CONTRACT.md'));
        self::assertStringContainsString('establish HTTPS and verify CA chain + hostname', (string) file_get_contents($root . '/docs/FIRMWARE_CONTRACT.md'));
        self::assertStringContainsString('Battery low/full thresholds belong only to the dashboard display', (string) file_get_contents($root . '/docs/FIRMWARE_CONTRACT.md'));
        self::assertStringContainsString('earliest due deadline', (string) file_get_contents($root . '/docs/FIRMWARE_IMPLEMENTATION_HANDOFF.md'));
        self::assertStringContainsString('configuration', (string) file_get_contents($root . '/docs/FIRMWARE_CONTRACT.md'));
        self::assertStringContainsString('DEVICE_CONFIG_VERSION_CONFLICT', (string) file_get_contents($root . '/docs/SENSOR_PROTOCOL_V1.md'));
        self::assertStringContainsString('WPA2-protected Wi-Fi SoftAP', (string) file_get_contents($root . '/docs/DEVICE_PROVISIONING.md'));
        self::assertStringContainsString('plaintext device key exists only', $openApi['paths']['/api/v1/dashboard/devices']['post']['description']);
    }
}
