<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\DashboardSettingsService;
use Haccp\Service\AuditService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DashboardSettingsController
{
    public function __construct(private DashboardSettingsService $service, private Config $config, private AuditService $audit)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);

        $result = $this->service->update((string) $arguments['device_uid'], $payload);
        $user = $request->getAttribute('dashboard_user');
        $this->audit->append('device.settings_updated', (int) $user['id'], 'device', (string) $arguments['device_uid'], ['config_version' => $result['config_version']]);

        return JsonResponse::write($response, $result)
            ->withHeader('Cache-Control', 'no-store');
    }
}
