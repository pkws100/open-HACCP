<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Service\DashboardService;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DashboardDataController
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $device = isset($query['device']) && is_string($query['device']) ? $query['device'] : null;
        $point = isset($query['point']) && is_string($query['point']) ? $query['point'] : null;
        $hours = isset($query['hours']) && is_numeric($query['hours']) ? (int) $query['hours'] : 24;
        $recentPage = $this->positiveInteger($query['recent_page'] ?? null) ?? 1;
        $recentSnapshotId = $this->positiveInteger($query['recent_snapshot_id'] ?? null);
        $recentOnly = ($query['recent_only'] ?? null) === '1';

        $data = $recentOnly
            ? $this->dashboard->recent($device, $point, $recentPage, $recentSnapshotId)
            : $this->dashboard->overview($device, $point, $hours, $recentPage, $recentSnapshotId);

        return JsonResponse::write($response, $data)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : $parsed;
    }
}
