<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Service\AnalysisService;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AnalysisController
{
    public function __construct(private AnalysisService $analysis)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $days = isset($query['days']) && is_numeric($query['days']) ? (int) $query['days'] : 30;
        $device = isset($query['device']) && is_string($query['device']) && $query['device'] !== '' ? $query['device'] : null;
        $pointValue = $query['measurement_point_id'] ?? $query['point'] ?? null;
        $point = $pointValue !== null && ctype_digit((string) $pointValue) ? (int) $pointValue : null;

        return JsonResponse::write($response, ['success' => true] + $this->analysis->get($days, $device, $point));
    }
}
