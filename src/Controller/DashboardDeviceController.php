<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\DeviceProvisioningService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DashboardDeviceController
{
    public function __construct(private DeviceProvisioningService $service, private Config $config)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);

        return JsonResponse::write($response, $this->service->create($payload), 201)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Pragma', 'no-cache');
    }
}
