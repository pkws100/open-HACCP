<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

use Haccp\ApplicationFactory;
use Haccp\Config;
use Haccp\Repository\DeviceConfigRepository;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Service\ApiKeyService;
use Haccp\Support\Clock;
use Haccp\Support\Database;
use Haccp\Tests\Support\MemoryLogger;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

abstract class IntegrationTestCase extends TestCase
{
    protected Config $config;
    protected PDO $pdo;
    protected App $app;
    protected MemoryLogger $logger;
    protected string $deviceUid = 'haccp-test-0001';
    protected string $deviceKey = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    protected string $sessionCookie = '';
    protected string $csrfToken = '';

    protected function setUp(): void
    {
        $this->config = Config::fromEnvironment();
        $this->pdo = Database::connect($this->config);
        $this->resetDatabase();
        $this->logger = new MemoryLogger();
        $this->app = ApplicationFactory::create($this->config, $this->pdo, $this->logger);
        $this->createFixtureDevice();
        $this->loginDashboard();
    }

    protected function request(string $method, string $path, ?array $payload = null, ?string $key = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($path !== '/health') {
            $request = $request
                ->withHeader('X-Device-ID', $this->deviceUid)
                ->withHeader('X-Device-Key', $key ?? $this->deviceKey);
        }
        if ($payload !== null) {
            $body = (new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($body);
        }

        return $this->app->handle($request);
    }

    /** @param array<string, mixed>|null $payload */
    protected function dashboardRequest(
        string $path,
        bool $authenticated = true,
        string $method = 'GET',
        ?array $payload = null,
    ): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($authenticated) {
            $request = $request
                ->withHeader('Cookie', 'haccp_session=' . $this->sessionCookie)
                ->withCookieParams(['haccp_session' => $this->sessionCookie]);
            if (!in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
                $request = $request->withHeader('X-CSRF-Token', $this->csrfToken);
            }
        }
        if ($payload !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>>|null $measurements @return array<string, mixed> */
    protected function batch(?array $measurements = null, string $batchId = 'batch-1'): array
    {
        $sent = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $measurements ??= [
            $this->measurement(1, $sent->modify('-15 minutes')),
            $this->measurement(2, $sent->modify('-10 minutes')),
            $this->measurement(3, $sent->modify('-5 minutes')),
        ];

        return [
            'protocol_version' => 1,
            'batch_id' => $batchId,
            'firmware_version' => '0.1.0',
            'hardware_revision' => 'prototype-a',
            'sent_at' => $sent->format('Y-m-d\TH:i:s\Z'),
            'diagnostics' => [
                'battery_mv' => 6127,
                'rssi_dbm' => -58,
                'wifi_connect_ms' => 1834,
                'boot_count' => 42,
            ],
            'measurements' => $measurements,
        ];
    }

    /** @return array<string, mixed> */
    protected function measurement(int $sequence, \DateTimeImmutable $measuredAt, string $point = 'fridge-1'): array
    {
        return [
            'measurement_point' => $point,
            'sequence' => $sequence,
            'measured_at' => $measuredAt->format('Y-m-d\TH:i:s\Z'),
            'temperature_c' => 4.1 + ($sequence / 100),
            'humidity_rh' => 69.8,
            'battery_mv' => 6127,
        ];
    }

    private function createFixtureDevice(): void
    {
        $clock = new Clock();
        $now = $clock->database($clock->now());
        $devices = new DeviceRepository($this->pdo);
        $deviceId = $devices->create(
            $this->deviceUid,
            'Integration test device',
            (new ApiKeyService($this->config->deviceKeyPepper))->hash($this->deviceKey),
            $now,
        );
        (new DeviceConfigRepository($this->pdo))->createDefault($deviceId, $now);
        (new MeasurementPointRepository($this->pdo))->create([
            'device_id' => $deviceId,
            'code' => 'fridge-1',
            'name' => 'Test fridge',
            'sensor_type' => 'SHT45',
            'location' => 'Test kitchen',
            'temperature_min_c' => null,
            'temperature_max_c' => null,
            'humidity_min_rh' => null,
            'humidity_max_rh' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function resetDatabase(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'event_verifications', 'corrective_action_revisions', 'corrective_actions', 'compliance_events',
            'export_jobs', 'audit_log', 'audit_chain_state', 'battery_cycles',
            'measurement_point_compliance_configs', 'establishments', 'user_sessions', 'login_attempts',
            'users', 'measurements', 'device_transmissions', 'device_configs', 'measurement_points', 'devices',
        ] as $table) {
            $this->pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $now = (new Clock())->database((new Clock())->now());
        $this->pdo->prepare("INSERT INTO audit_chain_state (id, head_hash, updated_at) VALUES (1, :hash, :now)")
            ->execute(['hash' => str_repeat('0', 64), 'now' => $now]);
        $this->pdo->prepare("INSERT INTO establishments
            (id, legal_name, address_line1, postal_code, city, country_code, timezone, general_retention_months, created_at, updated_at)
            VALUES (1, '', '', '', '', 'DE', 'Europe/Berlin', 24, :now, :now2)")
            ->execute(['now' => $now, 'now2' => $now]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function loginDashboard(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/auth/login')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode([
                'username' => $this->config->dashboardUsername,
                'password' => $this->config->dashboardPassword,
            ], JSON_THROW_ON_ERROR)));
        $response = $this->app->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody() . "\n" . json_encode($this->logger->records));
        $cookie = $response->getHeaderLine('Set-Cookie');
        preg_match('/haccp_session=([^;]+)/', $cookie, $matches);
        $this->sessionCookie = rawurldecode($matches[1] ?? '');
        $this->csrfToken = $this->json($response)['csrf_token'];
    }
}
