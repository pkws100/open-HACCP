<?php

declare(strict_types=1);

namespace Haccp;

use Haccp\Api\ApiException;
use Haccp\Controller\AnalysisController;
use Haccp\Controller\AuthController;
use Haccp\Controller\ComplianceController;
use Haccp\Controller\DeviceConfigController;
use Haccp\Controller\DashboardController;
use Haccp\Controller\DashboardDataController;
use Haccp\Controller\DashboardDeviceController;
use Haccp\Controller\DashboardSettingsController;
use Haccp\Controller\EventController;
use Haccp\Controller\ExportController;
use Haccp\Controller\HealthController;
use Haccp\Controller\HeartbeatController;
use Haccp\Controller\MeasurementController;
use Haccp\Controller\UserController;
use Haccp\Middleware\DeviceAuthenticationMiddleware;
use Haccp\Middleware\RequestIdMiddleware;
use Haccp\Middleware\RequestLoggingMiddleware;
use Haccp\Middleware\SessionAuthenticationMiddleware;
use Haccp\Repository\AnalysisRepository;
use Haccp\Repository\AuthRepository;
use Haccp\Repository\ComplianceRepository;
use Haccp\Repository\DeviceConfigRepository;
use Haccp\Repository\DashboardRepository;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\EventRepository;
use Haccp\Repository\ExportRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Repository\MeasurementRepository;
use Haccp\Repository\TransmissionRepository;
use Haccp\Service\ApiKeyService;
use Haccp\Service\AnalysisService;
use Haccp\Service\AuditService;
use Haccp\Service\AuthService;
use Haccp\Service\ComplianceEventService;
use Haccp\Service\ComplianceService;
use Haccp\Service\DeviceConfigService;
use Haccp\Service\DeviceProvisioningService;
use Haccp\Service\DashboardService;
use Haccp\Service\DashboardSettingsService;
use Haccp\Service\DeviceStatusService;
use Haccp\Service\EventWorkflowService;
use Haccp\Service\ExportService;
use Haccp\Service\GapDetector;
use Haccp\Service\HeartbeatService;
use Haccp\Service\MeasurementService;
use Haccp\Service\ProtocolValidator;
use Haccp\Service\UserService;
use Haccp\Support\Clock;
use Haccp\Support\Database;
use Haccp\Support\JsonResponse;
use Haccp\Support\LoggerFactory;
use PDO;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory as SlimAppFactory;
use Throwable;

final class ApplicationFactory
{
    public static function create(Config $config, ?PDO $pdo = null, ?LoggerInterface $logger = null): App
    {
        $pdo ??= Database::connect($config);
        $logger ??= LoggerFactory::create($config->environment);
        $clock = new Clock();

        $devices = new DeviceRepository($pdo);
        $measurementPoints = new MeasurementPointRepository($pdo);
        $measurements = new MeasurementRepository($pdo);
        $transmissions = new TransmissionRepository($pdo);
        $configs = new DeviceConfigRepository($pdo);
        $authRepository = new AuthRepository($pdo);
        $complianceRepository = new ComplianceRepository($pdo);
        $eventRepository = new EventRepository($pdo);
        $exportRepository = new ExportRepository($pdo);
        $audit = new AuditService($pdo, $clock, $config->auditLogKey);
        $auth = new AuthService($authRepository, $audit, $clock);
        $auth->bootstrapAdmin($config->dashboardUsername, $config->dashboardPassword);
        $users = new UserService($authRepository, $auth, $audit, $clock);
        $compliance = new ComplianceService($pdo, $complianceRepository, $authRepository, $audit, $clock);
        $eventTransitions = new ComplianceEventService($pdo, $eventRepository, $clock);
        $configService = new DeviceConfigService($configs, $measurementPoints, $clock);
        $dashboardRepository = new DashboardRepository($pdo);
        $deviceStatus = new DeviceStatusService();
        $dashboard = new DashboardService($dashboardRepository, $clock, $deviceStatus);
        $analysis = new AnalysisService(new AnalysisRepository($pdo), $dashboardRepository, $eventRepository, $clock);
        $eventWorkflow = new EventWorkflowService($pdo, $eventRepository, $authRepository, $devices, $auth, $audit, $clock);
        $exportService = new ExportService($exportRepository, $compliance, $audit, $clock);
        $dashboardSettings = new DashboardSettingsService(
            $pdo,
            $devices,
            $configs,
            $measurementPoints,
            $dashboardRepository,
            $deviceStatus,
            $configService,
            $clock,
        );
        $validator = new ProtocolValidator($clock, dirname(__DIR__) . '/docs/protocol-v1.schema.json');
        $keys = new ApiKeyService($config->deviceKeyPepper);
        $deviceProvisioning = new DeviceProvisioningService(
            $pdo,
            $devices,
            $measurementPoints,
            $configs,
            $keys,
            $clock,
            $config->publicApiBaseUrl,
        );

        $measurementService = new MeasurementService(
            $pdo,
            $validator,
            $devices,
            $measurementPoints,
            $measurements,
            $transmissions,
            $configService,
            $eventTransitions,
            new GapDetector(),
            $clock,
            $logger,
        );
        $heartbeatService = new HeartbeatService($pdo, $validator, $devices, $transmissions, $configService, $eventTransitions, $clock);

        $app = SlimAppFactory::create();
        $app->get('/health', new HealthController($pdo));
        $readAccess = new SessionAuthenticationMiddleware($auth, AuthService::ROLES);
        $writeAccess = new SessionAuthenticationMiddleware($auth, ['administrator', 'operator'], true);
        $adminRead = new SessionAuthenticationMiddleware($auth, ['administrator']);
        $adminWrite = new SessionAuthenticationMiddleware($auth, ['administrator'], true);
        $anyWrite = new SessionAuthenticationMiddleware($auth, AuthService::ROLES, true);
        $authController = new AuthController($auth, $config);
        $userController = new UserController($users, $config);
        $complianceController = new ComplianceController($compliance, $config);
        $eventController = new EventController($eventWorkflow, $config);
        $exportController = new ExportController($exportService, $config);

        $app->get('/login', new DashboardController(dirname(__DIR__) . '/resources/login.html'));
        $app->post('/api/v1/auth/login', [$authController, 'login']);
        $app->get('/api/v1/auth/me', [$authController, 'me'])->add($readAccess);
        $app->post('/api/v1/auth/logout', [$authController, 'logout'])->add($anyWrite);
        $app->put('/api/v1/auth/me/password', [$authController, 'password'])->add($anyWrite);

        $app->get('/dashboard', new DashboardController(dirname(__DIR__) . '/resources/dashboard.html'))->add($readAccess);
        $app->get('/api/v1/dashboard/overview', new DashboardDataController($dashboard))
            ->add($readAccess);
        $app->get('/api/v1/dashboard/analysis', new AnalysisController($analysis))->add($readAccess);
        $app->post('/api/v1/dashboard/devices', new DashboardDeviceController($deviceProvisioning, $config, $audit))->add($writeAccess);
        $app->put('/api/v1/dashboard/devices/{device_uid}/settings', new DashboardSettingsController($dashboardSettings, $config, $audit))->add($writeAccess);
        $app->post('/api/v1/dashboard/devices/{device_uid}/battery-replaced', [$eventController, 'batteryReplaced'])->add($writeAccess);

        $app->get('/api/v1/dashboard/events', [$eventController, 'list'])->add($readAccess);
        $app->get('/api/v1/dashboard/events/{id}', [$eventController, 'detail'])->add($readAccess);
        $app->post('/api/v1/dashboard/events/{id}/acknowledge', [$eventController, 'acknowledge'])->add($writeAccess);
        $app->post('/api/v1/dashboard/events/{id}/actions', [$eventController, 'action'])->add($writeAccess);
        $app->put('/api/v1/dashboard/actions/{id}', [$eventController, 'revise'])->add($writeAccess);
        $app->post('/api/v1/dashboard/actions/{id}/verify', [$eventController, 'verify'])->add($writeAccess);

        $app->get('/api/v1/dashboard/exports', [$exportController, 'list'])->add($readAccess);
        $app->post('/api/v1/dashboard/exports', [$exportController, 'create'])->add($anyWrite);
        $app->get('/api/v1/dashboard/exports/{id}', [$exportController, 'get'])->add($readAccess);
        $app->get('/api/v1/dashboard/exports/{id}/download', [$exportController, 'download'])->add($readAccess);

        $app->get('/api/v1/dashboard/users', [$userController, 'list'])->add($adminRead);
        $app->post('/api/v1/dashboard/users', [$userController, 'create'])->add($adminWrite);
        $app->put('/api/v1/dashboard/users/{id}', [$userController, 'update'])->add($adminWrite);
        $app->post('/api/v1/dashboard/users/{id}/reset-password', [$userController, 'resetPassword'])->add($adminWrite);

        $app->get('/api/v1/dashboard/establishment', [$complianceController, 'get'])->add($adminRead);
        $app->put('/api/v1/dashboard/establishment', [$complianceController, 'updateEstablishment'])->add($adminWrite);
        $app->put('/api/v1/dashboard/measurement-points/{id}/compliance', [$complianceController, 'updatePoint'])->add($adminWrite);
        $app->get('/api/v1/dashboard/compliance/preflight', [$complianceController, 'preflight'])->add($readAccess);
        $app->group('/api/v1/device', function ($group) use ($measurementService, $heartbeatService, $configService, $config): void {
            $group->post('/measurements', new MeasurementController($measurementService, $config));
            $group->post('/heartbeat', new HeartbeatController($heartbeatService, $config));
            $group->get('/config', new DeviceConfigController($configService));
        })->add(new DeviceAuthenticationMiddleware($devices, $keys));

        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware(false, true, true, $logger);
        $errorMiddleware->setDefaultErrorHandler(
            function (
                ServerRequestInterface $request,
                Throwable $exception,
                bool $displayErrorDetails,
                bool $logErrors,
                bool $logErrorDetails,
            ) use ($app, $logger, $config) {
                $status = 500;
                $code = 'INTERNAL_SERVER_ERROR';
                $message = 'An internal server error occurred';
                $details = [];

                if ($exception instanceof ApiException) {
                    $status = $exception->status;
                    $code = $exception->errorCode;
                    $message = $exception->getMessage();
                    $details = $exception->details;
                } elseif ($exception instanceof HttpNotFoundException) {
                    $status = 404;
                    $code = 'NOT_FOUND';
                    $message = 'The requested endpoint was not found';
                } elseif ($exception instanceof HttpMethodNotAllowedException) {
                    $status = 405;
                    $code = 'METHOD_NOT_ALLOWED';
                    $message = 'The HTTP method is not allowed for this endpoint';
                }

                if ($status >= 500) {
                    $context = [
                        'request_id' => $request->getAttribute('request_id'),
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ];
                    if ($config->debug) {
                        $context['trace'] = $exception->getTraceAsString();
                    }
                    $logger->error('request_failed', $context);
                }

                $error = ['code' => $code, 'message' => $message];
                if ($details !== []) {
                    $error['details'] = $details;
                }

                return JsonResponse::write($app->getResponseFactory()->createResponse(), [
                    'success' => false,
                    'error' => $error,
                ], $status);
            },
        );
        $app->add(new RequestLoggingMiddleware($logger));
        $app->add(new RequestIdMiddleware());

        return $app;
    }
}
