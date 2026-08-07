<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Domain\Device;
use Haccp\Service\MeasurementService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MeasurementController
{
    public function __construct(private MeasurementService $service, private Config $config)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $device = $request->getAttribute('device');
        assert($device instanceof Device);
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $result = $this->service->process(
            $device,
            $payload,
            (string) $request->getAttribute('request_id'),
            $this->remoteIp($request),
        );

        return JsonResponse::write($response, $result);
    }

    private function remoteIp(ServerRequestInterface $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
