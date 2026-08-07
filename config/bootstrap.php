<?php

declare(strict_types=1);

use Haccp\Config;
use Haccp\Repository\DeviceConfigRepository;
use Haccp\Repository\DeviceRepository;
use Haccp\Repository\MeasurementPointRepository;
use Haccp\Service\ApiKeyService;
use Haccp\Support\Clock;
use Haccp\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
$config = Config::fromEnvironment();
$pdo = Database::connect($config);

return [
    'config' => $config,
    'pdo' => $pdo,
    'clock' => new Clock(),
    'keys' => new ApiKeyService($config->deviceKeyPepper),
    'devices' => new DeviceRepository($pdo),
    'measurement_points' => new MeasurementPointRepository($pdo),
    'device_configs' => new DeviceConfigRepository($pdo),
];
