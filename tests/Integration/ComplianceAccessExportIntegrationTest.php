<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

use Haccp\Repository\ComplianceRepository;
use Haccp\Repository\ExportRepository;
use Haccp\Service\AuditService;
use Haccp\Service\ComplianceEventService;
use Haccp\Service\ExportGenerator;
use Haccp\Repository\EventRepository;
use Haccp\Support\Clock;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use ZipArchive;

final class ComplianceAccessExportIntegrationTest extends IntegrationTestCase
{
    public function testAuthenticationUsesArgon2idAndWriteRoutesRequireCsrf(): void
    {
        $hash = (string) $this->pdo->query("SELECT password_hash FROM users WHERE username = 'haccp-test'")->fetchColumn();
        self::assertStringStartsWith('$argon2id$', $hash);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/dashboard/exports')
            ->withHeader('Cookie', 'haccp_session=' . $this->sessionCookie)
            ->withCookieParams(['haccp_session' => $this->sessionCookie])
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($this->exportPayload('pdf'), JSON_THROW_ON_ERROR)));
        $response = $this->app->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('CSRF_FAILED', $this->json($response)['error']['code']);
    }

    public function testAdministratorCreatesTemporaryUserAndRoleRestrictionsApply(): void
    {
        $created = $this->dashboardRequest('/api/v1/dashboard/users', true, 'POST', [
            'username' => 'mitarbeit-test',
            'display_name' => 'Mitarbeit Test',
            'email' => 'mitarbeit@example.test',
            'role' => 'operator',
        ]);
        $body = $this->json($created);

        self::assertSame(201, $created->getStatusCode());
        self::assertTrue($body['user']['password_change_required']);
        self::assertGreaterThanOrEqual(12, mb_strlen($body['temporary_password']));

        [$cookie, $csrf] = $this->login('mitarbeit-test', $body['temporary_password']);
        $blocked = $this->sessionRequest('/api/v1/dashboard/overview', $cookie, $csrf);
        self::assertSame(403, $blocked->getStatusCode());
        self::assertSame('PASSWORD_CHANGE_REQUIRED', $this->json($blocked)['error']['code']);

        $changed = $this->sessionRequest('/api/v1/auth/me/password', $cookie, $csrf, 'PUT', [
            'current_password' => $body['temporary_password'],
            'new_password' => 'eine-neue-sichere-passphrase',
        ]);
        self::assertSame(200, $changed->getStatusCode());

        [$cookie, $csrf] = $this->login('mitarbeit-test', 'eine-neue-sichere-passphrase');
        self::assertSame(200, $this->sessionRequest('/api/v1/dashboard/overview', $cookie, $csrf)->getStatusCode());
        $forbidden = $this->sessionRequest('/api/v1/dashboard/users', $cookie, $csrf);
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('FORBIDDEN', $this->json($forbidden)['error']['code']);
    }

    public function testAuditorCanCreateAuthorityEvidenceButNotExtendedExports(): void
    {
        $created = $this->json($this->dashboardRequest('/api/v1/dashboard/users', true, 'POST', [
            'username' => 'pruefer-test',
            'display_name' => 'Prüfer Test',
            'role' => 'auditor',
        ]));
        [$cookie, $csrf] = $this->login('pruefer-test', $created['temporary_password']);
        self::assertSame(200, $this->sessionRequest('/api/v1/auth/me/password', $cookie, $csrf, 'PUT', [
            'current_password' => $created['temporary_password'],
            'new_password' => 'pruefer-sichere-passphrase',
        ])->getStatusCode());
        [$cookie, $csrf] = $this->login('pruefer-test', 'pruefer-sichere-passphrase');

        $authority = $this->sessionRequest('/api/v1/dashboard/exports', $cookie, $csrf, 'POST', $this->exportPayload('pdf'));
        self::assertSame(202, $authority->getStatusCode());
        self::assertSame('authority', $this->json($authority)['job']['mode']);

        $extended = $this->sessionRequest('/api/v1/dashboard/exports', $cookie, $csrf, 'POST', $this->exportPayload('xlsx', 'extended', ['battery']));
        self::assertSame(403, $extended->getStatusCode());
        self::assertSame('FORBIDDEN', $this->json($extended)['error']['code']);
    }

    public function testFiveFailedLoginsTemporarilyLockAccount(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = $this->loginResponse('haccp-test', 'absichtlich-falsch');
            self::assertSame(401, $response->getStatusCode());
        }

        $locked = $this->loginResponse('haccp-test', $this->config->dashboardPassword);
        self::assertSame(401, $locked->getStatusCode());
        self::assertSame('INVALID_CREDENTIALS', $this->json($locked)['error']['code']);
        self::assertNotNull($this->pdo->query("SELECT locked_until FROM users WHERE username = 'haccp-test'")->fetchColumn());
    }

    public function testFailedLoginWindowDoesNotCarryStaleCountersForward(): void
    {
        $this->pdo->exec("UPDATE users SET failed_login_count = 4 WHERE username = 'haccp-test'");
        $hash = hash('sha256', 'haccp-test');
        $statement = $this->pdo->prepare(
            "INSERT INTO login_attempts (username_hash, successful, attempted_at)
             VALUES (:hash, 0, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY))",
        );
        $statement->execute(['hash' => $hash]);

        self::assertSame(401, $this->loginResponse('haccp-test', 'noch-ein-fehler')->getStatusCode());
        $user = $this->pdo->query("SELECT failed_login_count, locked_until FROM users WHERE username = 'haccp-test'")->fetch();
        self::assertSame(1, (int) $user['failed_login_count']);
        self::assertNull($user['locked_until']);
    }

    public function testExpiredSessionIsRejected(): void
    {
        $statement = $this->pdo->prepare('UPDATE user_sessions SET idle_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE token_hash = :hash');
        $statement->execute(['hash' => hash('sha256', $this->sessionCookie)]);

        $response = $this->dashboardRequest('/api/v1/dashboard/overview');
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('AUTHENTICATION_REQUIRED', $this->json($response)['error']['code']);
    }

    public function testComplianceProfilesAreVersionedAndFrozenModuleNeedsEvidence(): void
    {
        $pointId = (int) $this->pdo->query("SELECT id FROM measurement_points WHERE code = 'fridge-1'")->fetchColumn();
        $userId = (int) $this->pdo->query("SELECT id FROM users WHERE username = 'haccp-test'")->fetchColumn();
        $establishment = $this->dashboardRequest('/api/v1/dashboard/establishment', true, 'PUT', [
            'legal_name' => 'HACCP Testbetrieb GmbH',
            'address_line1' => 'Prüfweg 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country_code' => 'DE',
            'timezone' => 'Europe/Berlin',
            'haccp_responsible_user_id' => $userId,
            'general_retention_months' => 24,
        ]);
        self::assertSame(200, $establishment->getStatusCode());

        $profile = $this->dashboardRequest('/api/v1/dashboard/measurement-points/' . $pointId . '/compliance', true, 'PUT', [
            'expected_config_version' => 1,
            'legal_profile' => 'quick_frozen',
            'control_classification' => 'CCP',
            'monitoring_purpose' => 'Lagerung tiefgefrorener Ware',
            'retention_months' => 12,
            'responsible_user_id' => $userId,
            'instrument_manufacturer' => 'Prototype Labs',
            'instrument_model' => 'SHT45 carrier',
            'instrument_serial' => 'TEST-001',
            'conformity_status' => 'not_documented',
        ]);
        self::assertSame(200, $profile->getStatusCode());
        self::assertSame(2, (int) $this->json($profile)['compliance']['config_version']);

        $conflict = $this->dashboardRequest('/api/v1/dashboard/measurement-points/' . $pointId . '/compliance', true, 'PUT', [
            'expected_config_version' => 1,
            'legal_profile' => 'general_haccp',
            'control_classification' => 'GHP',
            'monitoring_purpose' => 'Temperaturüberwachung',
            'retention_months' => 24,
            'responsible_user_id' => $userId,
        ]);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('COMPLIANCE_VERSION_CONFLICT', $this->json($conflict)['error']['code']);

        $preflight = $this->json($this->dashboardRequest('/api/v1/dashboard/compliance/preflight'));
        self::assertFalse($preflight['complete']);
        self::assertStringContainsString('Tiefkühlnachweis unvollständig', implode(' ', $preflight['issues']));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM measurement_point_compliance_configs')->fetchColumn());
    }

    public function testStateEventsDoNotDuplicateAndCorrectiveActionsUseImmutableRevisions(): void
    {
        $this->dashboardRequest('/api/v1/dashboard/devices/' . $this->deviceUid . '/settings', true, 'PUT', $this->settingsPayload());
        $sent = new \DateTimeImmutable('-2 minutes', new \DateTimeZone('UTC'));
        $first = $this->measurement(1, $sent); $first['temperature_c'] = 8.2;
        $second = $this->measurement(2, $sent->modify('+30 seconds')); $second['temperature_c'] = 8.4;
        $this->request('POST', '/api/v1/device/measurements', $this->batch([$first], 'event-1'));
        $this->request('POST', '/api/v1/device/measurements', $this->batch([$second], 'event-2'));

        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'temperature_above_max'")->fetchColumn());
        $eventId = (int) $this->pdo->query("SELECT id FROM compliance_events WHERE event_type = 'temperature_above_max'")->fetchColumn();
        self::assertSame(200, $this->dashboardRequest('/api/v1/dashboard/events/' . $eventId . '/acknowledge', true, 'POST')->getStatusCode());

        $action = $this->dashboardRequest('/api/v1/dashboard/events/' . $eventId . '/actions', true, 'POST', $this->actionPayload('Ware separiert'));
        self::assertSame(201, $action->getStatusCode());
        $actionId = (int) $this->json($action)['actions'][0]['id'];
        $revised = $this->dashboardRequest('/api/v1/dashboard/actions/' . $actionId, true, 'PUT', $this->actionPayload('Ware verworfen') + ['expected_revision' => 1]);
        self::assertSame(200, $revised->getStatusCode());
        self::assertCount(2, $this->json($revised)['action_revisions']);
        self::assertSame(2, (int) $this->pdo->query('SELECT current_revision FROM corrective_actions WHERE id = ' . $actionId)->fetchColumn());

        $verified = $this->dashboardRequest('/api/v1/dashboard/actions/' . $actionId . '/verify', true, 'POST', [
            'note' => 'Maßnahme und Warenentscheidung geprüft.',
            'password' => $this->config->dashboardPassword,
        ]);
        self::assertSame(200, $verified->getStatusCode());
        self::assertSame('verified', $this->json($verified)['event']['state']);

        $recovered = $this->measurement(3, $sent->modify('+1 minute')); $recovered['temperature_c'] = 7.0;
        $this->request('POST', '/api/v1/device/measurements', $this->batch([$recovered], 'event-recovered'));
        self::assertSame('resolved', (string) $this->pdo->query('SELECT state FROM compliance_events WHERE id = ' . $eventId)->fetchColumn());
        self::assertNotNull($this->pdo->query('SELECT closed_at FROM compliance_events WHERE id = ' . $eventId)->fetchColumn());

        $immutable = $this->dashboardRequest('/api/v1/dashboard/actions/' . $actionId, true, 'PUT', $this->actionPayload('Nicht zulässig') + ['expected_revision' => 2]);
        self::assertSame(409, $immutable->getStatusCode());
        self::assertSame('ACTION_IMMUTABLE', $this->json($immutable)['error']['code']);
    }

    public function testAuditChainDetectsManipulation(): void
    {
        $audit = new AuditService($this->pdo, new Clock(), $this->config->auditLogKey);
        self::assertTrue($audit->verify()['valid']);

        $firstId = (int) $this->pdo->query('SELECT MIN(id) FROM audit_log')->fetchColumn();
        $statement = $this->pdo->prepare("UPDATE audit_log SET action = 'tampered' WHERE id = :id");
        $statement->execute(['id' => $firstId]);

        $result = $audit->verify();
        self::assertFalse($result['valid']);
        self::assertSame($firstId, $result['invalid_id']);
    }

    public function testDiagnosticAndOfflineEventsOpenAndRecoverWithoutDuplicates(): void
    {
        $heartbeat = [
            'protocol_version' => 1, 'firmware_version' => '0.3.0', 'hardware_revision' => 'prototype-b',
            'battery_mv' => 5300, 'rssi_dbm' => -82, 'wifi_connect_ms' => 2400, 'boot_count' => 9,
            'errors' => ['SENSOR_RECOVERED'],
        ];
        self::assertSame(200, $this->request('POST', '/api/v1/device/heartbeat', $heartbeat)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/v1/device/heartbeat', $heartbeat)->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'battery_low'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'signal_weak'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type = 'firmware_diagnostic'")->fetchColumn());
        self::assertSame('["SENSOR_RECOVERED"]', (string) $this->pdo->query('SELECT diagnostic_errors_json FROM device_transmissions ORDER BY id DESC LIMIT 1')->fetchColumn());

        $heartbeat['battery_mv'] = 6100; $heartbeat['rssi_dbm'] = -60; $heartbeat['errors'] = [];
        $this->request('POST', '/api/v1/device/heartbeat', $heartbeat);
        self::assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM compliance_events WHERE event_type IN ('battery_low','signal_weak','firmware_diagnostic') AND closed_at IS NOT NULL")->fetchColumn());

        $this->pdo->exec("UPDATE devices SET last_seen_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY) WHERE device_uid = 'haccp-test-0001'");
        $events = new ComplianceEventService($this->pdo, new EventRepository($this->pdo), new Clock());
        self::assertSame(1, $events->reconcileOffline());
        self::assertSame(0, $events->reconcileOffline());
        $this->request('POST', '/api/v1/device/heartbeat', $heartbeat);
        self::assertNotNull($this->pdo->query("SELECT closed_at FROM compliance_events WHERE event_type = 'device_offline'")->fetchColumn());
    }

    public function testAnalysisProvidesBatteryEstimateAfterEnoughDecliningData(): void
    {
        $sent = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $measurements = [];
        for ($index = 0; $index < 20; $index++) {
            $measurement = $this->measurement($index + 1, $sent->modify(sprintf('-%d hours', (19 - $index) * 12)));
            $measurement['battery_mv'] = 6100 - ($index * 10);
            $measurements[] = $measurement;
        }
        self::assertSame(200, $this->request('POST', '/api/v1/device/measurements', $this->batch($measurements, 'battery-analysis'))->getStatusCode());
        $analysis = $this->json($this->dashboardRequest('/api/v1/dashboard/analysis?days=30&device=' . $this->deviceUid));
        self::assertSame('estimated', $analysis['battery']['status']);
        self::assertGreaterThanOrEqual(0, $analysis['battery']['estimated_days_remaining']);
        self::assertContains($analysis['battery']['confidence'], ['low', 'medium', 'high']);
    }

    public function testWorkerArtifactsHaveExpectedPdfXlsxAndCsvStructure(): void
    {
        $this->completeComplianceProfile();
        $this->request('POST', '/api/v1/device/measurements', $this->batch());
        $directory = sys_get_temp_dir() . '/haccp-export-test-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        try {
            $pdf = $this->generateExport('authority', 'pdf', $directory);
            self::assertStringStartsWith('%PDF-', (string) file_get_contents($pdf));

            $csv = $this->generateExport('authority', 'csv', $directory);
            $zip = new ZipArchive(); self::assertTrue($zip->open($csv) === true);
            self::assertSame(['deviations.csv', 'manifest.csv', 'measurements.csv'], $this->zipEntries($zip));
            $measurements = (string) $zip->getFromName('measurements.csv');
            self::assertStringStartsWith("\xEF\xBB\xBF", $measurements);
            self::assertStringNotContainsString('battery_mv', $measurements);
            $zip->close();

            $xlsx = $this->generateExport('extended', 'xlsx', $directory, ['battery', 'rssi', 'transmissions', 'configuration']);
            $zip = new ZipArchive(); self::assertTrue($zip->open($xlsx) === true);
            $workbook = (string) $zip->getFromName('xl/workbook.xml');
            foreach (['Nachweis', 'Messwerte', 'Abweichungen', 'Datenqualität', 'Diagnose', 'Übertragungen', 'Konfiguration'] as $sheet) {
                self::assertStringContainsString('name="' . $sheet . '"', $workbook);
            }
            $zip->close();
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testLongExportRangeIsSplitIntoJobsOfAtMost366Days(): void
    {
        $payload = $this->exportPayload('pdf');
        $payload['from'] = (new \DateTimeImmutable('-400 days'))->format('Y-m-d');
        $response = $this->dashboardRequest('/api/v1/dashboard/exports', true, 'POST', $payload);
        $body = $this->json($response);

        self::assertSame(202, $response->getStatusCode());
        self::assertTrue($body['split']);
        self::assertCount(2, $body['jobs']);
        foreach ($body['jobs'] as $job) {
            $from = new \DateTimeImmutable($job['parameters']['from']);
            $to = new \DateTimeImmutable($job['parameters']['to']);
            self::assertLessThanOrEqual(366 * 86400, $to->getTimestamp() - $from->getTimestamp());
        }
    }

    /** @return array{0:string,1:string} */
    private function login(string $username, string $password): array
    {
        $response = $this->loginResponse($username, $password);
        self::assertSame(200, $response->getStatusCode());
        preg_match('/haccp_session=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $matches);

        return [rawurldecode($matches[1] ?? ''), (string) $this->json($response)['csrf_token']];
    }

    private function loginResponse(string $username, string $password): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/auth/login')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR)));

        return $this->app->handle($request);
    }

    private function sessionRequest(string $path, string $cookie, string $csrf, string $method = 'GET', ?array $payload = null): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withHeader('Cookie', 'haccp_session=' . $cookie)
            ->withCookieParams(['haccp_session' => $cookie]);
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $request = $request->withHeader('X-CSRF-Token', $csrf);
        }
        if ($payload !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));
        }

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(): array
    {
        return [
            'expected_config_version' => 1,
            'alarm' => ['enabled' => true, 'temperature_min_c' => 2.0, 'temperature_max_c' => 7.0],
            'battery' => ['low_threshold_mv' => 5600, 'full_threshold_mv' => 6000],
            'schedule' => [
                'default_measurement_interval_seconds' => 300,
                'upload_interval_seconds' => 3600,
                'measurement_points' => [['measurement_point' => 'fridge-1', 'interval_seconds' => 300]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function actionPayload(string $disposition): array
    {
        return [
            'cause' => 'Tür stand während der Warenannahme offen.',
            'action_taken' => 'Tür geschlossen und Temperatur erneut kontrolliert.',
            'product_disposition' => $disposition,
            'preventive_follow_up' => 'Team unterwiesen.',
            'performed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function exportPayload(string $format, string $mode = 'authority', array $fields = []): array
    {
        return [
            'mode' => $mode,
            'format' => $format,
            'legal_profile' => 'configured',
            'from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'to' => (new \DateTimeImmutable('now'))->format('Y-m-d'),
            'device_uids' => [],
            'measurement_point_ids' => [],
            'extended_fields' => $fields,
        ];
    }

    private function completeComplianceProfile(): void
    {
        $userId = (int) $this->pdo->query("SELECT id FROM users WHERE username = 'haccp-test'")->fetchColumn();
        $pointId = (int) $this->pdo->query("SELECT id FROM measurement_points WHERE code = 'fridge-1'")->fetchColumn();
        $this->dashboardRequest('/api/v1/dashboard/establishment', true, 'PUT', [
            'legal_name' => 'HACCP Testbetrieb GmbH', 'address_line1' => 'Prüfweg 1', 'postal_code' => '10115',
            'city' => 'Berlin', 'country_code' => 'DE', 'timezone' => 'Europe/Berlin',
            'haccp_responsible_user_id' => $userId, 'general_retention_months' => 24,
        ]);
        $this->dashboardRequest('/api/v1/dashboard/measurement-points/' . $pointId . '/compliance', true, 'PUT', [
            'expected_config_version' => 1, 'legal_profile' => 'general_haccp', 'control_classification' => 'CCP',
            'monitoring_purpose' => 'Temperaturüberwachung Kühlgerät', 'retention_months' => 24,
            'responsible_user_id' => $userId, 'instrument_manufacturer' => 'Prototype Labs',
            'instrument_model' => 'SHT45 carrier', 'instrument_serial' => 'TEST-001',
            'conformity_status' => 'documented', 'conformity_reference' => 'CONF-TEST-001',
            'calibration_reference' => 'CAL-TEST-001', 'verification_reference' => 'VER-TEST-001',
        ]);
        $this->dashboardRequest('/api/v1/dashboard/devices/' . $this->deviceUid . '/settings', true, 'PUT', $this->settingsPayload());
    }

    private function generateExport(string $mode, string $format, string $directory, array $fields = []): string
    {
        $response = $this->dashboardRequest('/api/v1/dashboard/exports', true, 'POST', $this->exportPayload($format, $mode, $fields));
        self::assertSame(202, $response->getStatusCode());
        $publicId = (string) $this->json($response)['job']['public_id'];
        $repository = new ExportRepository($this->pdo);
        $job = $repository->job($publicId);
        self::assertNotNull($job);
        $generator = new ExportGenerator(
            $repository,
            new ComplianceRepository($this->pdo),
            new AuditService($this->pdo, new Clock(), $this->config->auditLogKey),
            new Clock(),
            $directory,
        );
        $file = $generator->generate($job);
        self::assertSame(64, strlen((string) $file['sha256']));

        return (string) $file['file_path'];
    }

    /** @return list<string> */
    private function zipEntries(ZipArchive $zip): array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = (string) $zip->getNameIndex($index);
        }
        sort($entries);

        return $entries;
    }
}
