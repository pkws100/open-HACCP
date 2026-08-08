#!/usr/bin/env php
<?php

declare(strict_types=1);

use Haccp\Demo\DemoDeviceProvisioner;
use Haccp\Demo\DemoFleetRunner;
use Haccp\Demo\DemoHttpClient;
use Haccp\Demo\DemoPayloadFactory;
use Haccp\Demo\DemoStateStore;

$services = require dirname(__DIR__) . '/config/bootstrap.php';
$options = getopt('', ['once', 'url::', 'interval::', 'state-file::']);
$once = array_key_exists('once', $options);
$url = (string) ($options['url'] ?? getenv('DEMO_API_URL') ?: 'http://app');
$interval = (int) ($options['interval'] ?? getenv('DEMO_INTERVAL_SECONDS') ?: 300);
$stateFile = (string) ($options['state-file'] ?? getenv('DEMO_STATE_FILE') ?: '/var/lib/haccp-demo/state.json');

if (!preg_match('#^https?://#', $url) || $interval < 1) {
    fwrite(STDERR, "Usage: php tools/demo_fleet.php [--once] [--url=https://example.test] [--interval=300]\n");
    exit(2);
}

$store = new DemoStateStore($stateFile);
$runner = new DemoFleetRunner(
    $store,
    new DemoDeviceProvisioner(
        $services['pdo'],
        $services['devices'],
        $services['measurement_points'],
        $services['device_configs'],
        $services['keys'],
        $services['clock'],
    ),
    new DemoPayloadFactory(),
    new DemoHttpClient($url),
);

do {
    try {
        $cycle = $runner->cycle();
        foreach ($cycle['results'] as $result) {
            fwrite(STDOUT, sprintf(
                "%s heartbeat=%d batch=%d measurements=%d acknowledged=%s\n",
                $result['device_uid'],
                $result['heartbeat_status'],
                $result['batch_status'],
                $result['measurement_count'],
                $result['acknowledged'] ? 'yes' : 'no',
            ));
        }
        if ($once) {
            exit($cycle['success'] ? 0 : 1);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("Demo cycle failed: %s\n", $exception->getMessage()));
        if ($once) {
            exit(1);
        }
    }

    sleep($interval);
} while (true);
