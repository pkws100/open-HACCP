<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\DeviceProvisioningService;
use Haccp\Service\AuditService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DashboardDeviceController
{
    public function __construct(private DeviceProvisioningService $service, private Config $config, private AuditService $audit)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);

        $result = $this->service->create($payload);
        $user = $request->getAttribute('dashboard_user');
        $this->audit->append('device.created', (int) $user['id'], 'device', (string) $result['device']['device_uid']);

        return JsonResponse::write($response, $result, 201)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Pragma', 'no-cache');
    }
}
