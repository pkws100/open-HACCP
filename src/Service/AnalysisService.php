<?php

declare(strict_types=1);

namespace Haccp\Service;

use Haccp\Api\ApiException;
use Haccp\Repository\AnalysisRepository;
use Haccp\Repository\DashboardRepository;
use Haccp\Repository\EventRepository;
use Haccp\Support\Clock;

final readonly class AnalysisService
{
    public function __construct(
        private AnalysisRepository $analysis,
        private DashboardRepository $dashboard,
        private EventRepository $events,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(int $days, ?string $deviceUid, ?int $pointId): array
    {
        if (!in_array($days, [7, 30, 90], true)) {
            throw new ApiException(422, 'INVALID_ANALYSIS_RANGE', 'Der Analysezeitraum muss 7, 30 oder 90 Tage betragen.');
        }
        $now = $this->clock->now();
        $from = $this->clock->database($now->modify(sprintf('-%d days', $days)));
        $to = $this->clock->database($now);
        $measurements = $this->analysis->measurements($from, $to, $deviceUid, $pointId);
        $battery = $this->batteryForecast($measurements, $deviceUid);
        $kpis = $this->analysis->fleetKpis($from, $to, $deviceUid, $pointId);
        foreach ($kpis as $key => $value) {
            $kpis[$key] = (int) $value;
        }
        $availability = array_map(static function (array $row): array {
            $expected = max(1, (int) $row['expected_transmissions']);
            $received = (int) $row['transmissions'];

            return [
                'device_uid' => $row['device_uid'],
                'name' => $row['name'],
                'upload_interval_seconds' => (int) $row['upload_interval_seconds'],
                'transmissions' => $received,
                'expected_transmissions' => $expected,
                'availability_percent' => round(min(100, ($received / $expected) * 100), 1),
                'last_seen_at' => $row['last_seen_at'],
            ];
        }, $this->analysis->availability($from, $to, $deviceUid));

        return [
            'range' => ['days' => $days, 'from' => $from, 'to' => $to],
            'filters' => ['device' => $deviceUid, 'measurement_point_id' => $pointId],
            'fleet' => $kpis,
            'measurements' => array_map(static fn (array $row): array => [
                'measured_at' => $row['measured_at'],
                'temperature_c' => (float) $row['temperature_c'],
                'humidity_rh' => (float) $row['humidity_rh'],
                'battery_mv' => (int) $row['battery_mv'],
                'device_uid' => $row['device_uid'],
                'point_code' => $row['point_code'],
            ], $measurements),
            'events_by_day' => $this->analysis->eventDaily($from, $to, $deviceUid),
            'connections_by_day' => $this->analysis->transmissionDaily($from, $to, $deviceUid),
            'availability' => $availability,
            'battery' => $battery,
        ];
    }

    /** @return array<string, mixed> */
    private function batteryForecast(array $measurements, ?string $deviceUid): array
    {
        if ($deviceUid === null) {
            return ['status' => 'device_required', 'estimated_days_remaining' => null, 'confidence' => null, 'series' => []];
        }
        if ($measurements === []) {
            return ['status' => 'insufficient_data', 'estimated_days_remaining' => null, 'confidence' => null, 'series' => []];
        }
        $deviceId = (int) end($measurements)['device_id'];
        $cycle = $this->events->latestBatteryCycle($deviceId);
        if ($cycle !== null && !(bool) $cycle['forecast_enabled']) {
            return ['status' => 'disabled', 'estimated_days_remaining' => null, 'confidence' => null, 'series' => []];
        }
        $cycleStart = $cycle['started_at'] ?? null;
        $cutoff = $this->clock->now()->modify('-30 days')->getTimestamp();
        $points = [];
        foreach ($measurements as $row) {
            if ($deviceUid !== null && $row['device_uid'] !== $deviceUid) {
                continue;
            }
            $timestamp = (new \DateTimeImmutable((string) $row['measured_at'], new \DateTimeZone('UTC')))->getTimestamp();
            if ($timestamp < $cutoff || ($cycleStart !== null && $timestamp < strtotime((string) $cycleStart))) {
                continue;
            }
            $points[] = ['at' => $row['measured_at'], 'timestamp' => $timestamp, 'mv' => (int) $row['battery_mv']];
        }
        if ($points === []) {
            return ['status' => 'insufficient_data', 'estimated_days_remaining' => null, 'confidence' => null, 'series' => []];
        }
        $device = $this->dashboard->deviceByUid((string) end($measurements)['device_uid']);
        $low = (int) ($device['battery_low_mv'] ?? 5600);
        $start = $points[0]['timestamp'];
        $spanDays = (end($points)['timestamp'] - $start) / 86400;
        if (count($points) < 20 || $spanDays < 7) {
            return ['status' => 'insufficient_data', 'estimated_days_remaining' => null, 'confidence' => null, 'low_threshold_mv' => $low, 'series' => $points];
        }
        $xs = array_map(static fn (array $point): float => ($point['timestamp'] - $start) / 86400, $points);
        $ys = array_column($points, 'mv');
        $meanX = array_sum($xs) / count($xs);
        $meanY = array_sum($ys) / count($ys);
        $numerator = 0.0;
        $denominator = 0.0;
        foreach ($xs as $index => $x) {
            $numerator += ($x - $meanX) * ($ys[$index] - $meanY);
            $denominator += ($x - $meanX) ** 2;
        }
        $slope = $denominator === 0.0 ? 0.0 : $numerator / $denominator;
        $intercept = $meanY - $slope * $meanX;
        $ssResidual = 0.0;
        $ssTotal = 0.0;
        foreach ($xs as $index => $x) {
            $predicted = $intercept + $slope * $x;
            $ssResidual += ($ys[$index] - $predicted) ** 2;
            $ssTotal += ($ys[$index] - $meanY) ** 2;
        }
        $r2 = $ssTotal === 0.0 ? 0.0 : max(0.0, 1 - ($ssResidual / $ssTotal));
        if ($slope >= -0.1) {
            return ['status' => 'non_declining', 'estimated_days_remaining' => null, 'confidence' => null, 'low_threshold_mv' => $low, 'slope_mv_per_day' => round($slope, 3), 'series' => $points];
        }
        $latest = (int) end($points)['mv'];
        $eta = max(0, min(730, (int) round(($latest - $low) / abs($slope))));
        $confidence = $r2 >= 0.75 && $spanDays >= 21 && count($points) >= 100 ? 'high' : ($r2 >= 0.45 && $spanDays >= 14 ? 'medium' : 'low');

        return [
            'status' => 'estimated',
            'estimated_days_remaining' => $eta,
            'confidence' => $confidence,
            'slope_mv_per_day' => round($slope, 3),
            'fit_r2' => round($r2, 3),
            'sample_count' => count($points),
            'span_days' => round($spanDays, 1),
            'low_threshold_mv' => $low,
            'trend' => ['start_mv' => round($intercept, 1), 'end_mv' => round($intercept + $slope * end($xs), 1)],
            'series' => $points,
        ];
    }
}
