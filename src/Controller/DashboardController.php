<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final readonly class DashboardController
{
    public function __construct(private string $dashboardPath)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $html = file_get_contents($this->dashboardPath);
        if ($html === false) {
            throw new RuntimeException('Dashboard template could not be loaded.');
        }
        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
