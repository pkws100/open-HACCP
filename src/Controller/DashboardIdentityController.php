<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\DashboardIdentityService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DashboardIdentityController
{
    public function __construct(private DashboardIdentityService $service, private Config $config)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, $this->service->update(
            (string) $arguments['device_uid'],
            $payload,
            (int) $user['id'],
        ))->withHeader('Cache-Control', 'no-store');
    }
}
