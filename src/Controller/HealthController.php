<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Support\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class HealthController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
        } catch (Throwable) {
            return JsonResponse::write($response, [
                'status' => 'unavailable',
                'service' => 'haccp-monitor-backend',
                'database' => 'unavailable',
            ], 503);
        }

        return JsonResponse::write($response, [
            'status' => 'ok',
            'service' => 'haccp-monitor-backend',
            'database' => 'ok',
        ]);
    }
}
