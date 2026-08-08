<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\DashboardSettingsService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DashboardSettingsController
{
    public function __construct(private DashboardSettingsService $service, private Config $config)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);

        return JsonResponse::write($response, $this->service->update((string) $arguments['device_uid'], $payload))
            ->withHeader('Cache-Control', 'no-store');
    }
}
