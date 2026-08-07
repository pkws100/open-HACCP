<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Domain\Device;
use Haccp\Service\DeviceConfigService;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DeviceConfigController
{
    public function __construct(private DeviceConfigService $service)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $device = $request->getAttribute('device');
        assert($device instanceof Device);

        return JsonResponse::write($response, $this->service->get($device));
    }
}
