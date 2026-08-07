<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Domain\Device;
use Haccp\Service\HeartbeatService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HeartbeatController
{
    public function __construct(private HeartbeatService $service, private Config $config)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $device = $request->getAttribute('device');
        assert($device instanceof Device);
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $remoteIp = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;

        return JsonResponse::write($response, $this->service->process(
            $device,
            $payload,
            (string) $request->getAttribute('request_id'),
            $remoteIp,
        ));
    }
}
