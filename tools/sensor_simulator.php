#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = getopt('', [
    'device:', 'key::', 'url::', 'mode::', 'measurement-point::', 'count::',
    'temperature::', 'humidity::', 'battery::', 'rssi::', 'resend',
]);

$device = $options['device'] ?? '';
$key = $options['key'] ?? getenv('DEVICE_KEY');
$url = rtrim((string) ($options['url'] ?? 'http://localhost:8080'), '/');
$mode = (string) ($options['mode'] ?? 'batch');
$point = (string) ($options['measurement-point'] ?? 'fridge-1');
$count = (int) ($options['count'] ?? 3);
$temperature = (float) ($options['temperature'] ?? 4.2);
$humidity = (float) ($options['humidity'] ?? 70.0);
$battery = (int) ($options['battery'] ?? 6127);
$rssi = (int) ($options['rssi'] ?? -58);
$resend = array_key_exists('resend', $options);

if (!is_string($device) || $device === '' || !is_string($key) || $key === ''
    || !in_array($mode, ['batch', 'heartbeat', 'both'], true) || $count < 1 || $count > 500) {
    fwrite(STDERR, "Usage: php tools/sensor_simulator.php --device=haccp-p01-0001 --key=SECRET [--url=http://localhost:8080] [--mode=batch|heartbeat|both] [--count=3] [--resend]\n");
    exit(2);
}

$runtimeDirectory = dirname(__DIR__) . '/.runtime';
if (!is_dir($runtimeDirectory) && !mkdir($runtimeDirectory, 0770, true) && !is_dir($runtimeDirectory)) {
    fwrite(STDERR, "Could not create simulator runtime directory.\n");
    exit(1);
}
$stateFile = $runtimeDirectory . '/simulator-state.json';
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
if (!is_array($state)) {
    $state = [];
}
$stateKey = $device . ':' . $point;
$deviceState = $state[$stateKey] ?? ['sequence' => 0, 'boot_count' => 0, 'upload_count' => 0];
$deviceState['boot_count']++;

$send = static function (string $endpoint, array $payload) use ($device, $key, $url): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'X-Device-ID: ' . $device,
                'X-Device-Key: ' . $key,
            ]),
            'content' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $body = file_get_contents($url . $endpoint, false, $context);
    $headers = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $match);

    return ['status' => isset($match[1]) ? (int) $match[1] : 0, 'body' => $body === false ? '' : $body];
};

$printResponse = static function (string $label, array $response): void {
    fwrite(STDOUT, sprintf("%s HTTP %d\n%s\n", $label, $response['status'], $response['body']));
};

$diagnostics = [
    'battery_mv' => $battery,
    'rssi_dbm' => $rssi,
    'wifi_connect_ms' => 1834,
    'boot_count' => $deviceState['boot_count'],
];

if ($mode === 'heartbeat' || $mode === 'both') {
    $heartbeat = [
        'protocol_version' => 1,
        'firmware_version' => '0.1.0-simulator',
        'hardware_revision' => 'prototype-a',
        ...$diagnostics,
    ];
    $printResponse('Heartbeat', $send('/api/v1/device/heartbeat', $heartbeat));
}

if ($mode === 'batch' || $mode === 'both') {
    $deviceState['upload_count']++;
    $sentAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $measurements = [];
    for ($index = 0; $index < $count; $index++) {
        $deviceState['sequence']++;
        $measuredAt = $sentAt->modify(sprintf('-%d seconds', ($count - $index) * 300));
        $measurements[] = [
            'measurement_point' => $point,
            'sequence' => $deviceState['sequence'],
            'measured_at' => $measuredAt->format('Y-m-d\TH:i:s\Z'),
            'temperature_c' => round($temperature + (($index % 3) - 1) * 0.08, 2),
            'humidity_rh' => round($humidity + (($index % 3) - 1) * 0.3, 2),
            'battery_mv' => max(0, $battery - $index),
        ];
    }
    $batch = [
        'protocol_version' => 1,
        'batch_id' => sprintf('%08d-%08d', $deviceState['boot_count'], $deviceState['upload_count']),
        'firmware_version' => '0.1.0-simulator',
        'hardware_revision' => 'prototype-a',
        'sent_at' => $sentAt->format('Y-m-d\TH:i:s\Z'),
        'diagnostics' => $diagnostics,
        'measurements' => $measurements,
    ];
    $printResponse('Measurement batch', $send('/api/v1/device/measurements', $batch));
    if ($resend) {
        $printResponse('Repeated measurement batch', $send('/api/v1/device/measurements', $batch));
    }
}

$state[$stateKey] = $deviceState;
file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
