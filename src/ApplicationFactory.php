<?php

declare(strict_types=1);

namespace Haccp;

use Haccp\Api\ApiException;
use Haccp\Controller\DeviceConfigController;
use Haccp\Controller\DashboardController;
use Haccp\Controller\DashboardDataController;
use Haccp\Controller\DashboardDeviceController;
use Haccp\Controller\DashboardSettingsController;
use Haccp\Controller\HealthController;
use Haccp\Controller\HeartbeatController;
use Haccp\Controller\MeasurementController;
use Haccp\Middleware\DeviceAuthenticationMiddleware;
use Haccp\Middleware\DashboardAuthenticationMiddleware;
use Haccp\Middleware\RequestIdMiddleware;
use Haccp\Middleware\RequestLoggingMiddleware;
use Haccp\Repository\DeviceConfigRepository;
use Haccp\Repository\DashboardRepository;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Repository\MeasurementRepository;
use Haccp\Repository\TransmissionRepository;
use Haccp\Service\ApiKeyService;
use Haccp\Service\DeviceConfigService;
use Haccp\Service\DeviceProvisioningService;
use Haccp\Service\DashboardService;
use Haccp\Service\DashboardSettingsService;
use Haccp\Service\DeviceStatusService;
use Haccp\Service\GapDetector;
use Haccp\Service\HeartbeatService;
use Haccp\Service\MeasurementService;
use Haccp\Service\ProtocolValidator;
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
        $dashboardRepository = new DashboardRepository($pdo);
        $deviceStatus = new DeviceStatusService();
        $dashboard = new DashboardService($dashboardRepository, $clock, $deviceStatus);
        $dashboardSettings = new DashboardSettingsService(
            $pdo,
            $devices,
            $configs,
            $dashboardRepository,
            $deviceStatus,
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
            $configs,
            new GapDetector(),
            $clock,
            $logger,
        );
        $heartbeatService = new HeartbeatService($pdo, $validator, $devices, $transmissions, $configs, $clock);
        $configService = new DeviceConfigService($configs, $clock);

        $app = SlimAppFactory::create();
        $app->get('/health', new HealthController($pdo));
        $dashboardAuthentication = new DashboardAuthenticationMiddleware(
            $config->dashboardUsername,
            $config->dashboardPassword,
        );
        $app->get('/dashboard', new DashboardController(dirname(__DIR__) . '/resources/dashboard.html'))
            ->add($dashboardAuthentication);
        $app->get('/api/v1/dashboard/overview', new DashboardDataController($dashboard))
            ->add($dashboardAuthentication);
        $app->post('/api/v1/dashboard/devices', new DashboardDeviceController($deviceProvisioning, $config))
            ->add($dashboardAuthentication);
        $app->put('/api/v1/dashboard/devices/{device_uid}/settings', new DashboardSettingsController($dashboardSettings, $config))
            ->add($dashboardAuthentication);
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
