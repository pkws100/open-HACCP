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

        return JsonResponse::write($response, $this->dashboard->overview($device, $point, $hours))
            ->withHeader('Cache-Control', 'no-store');
    }
}
