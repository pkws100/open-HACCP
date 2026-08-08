<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\ComplianceService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ComplianceController
{
    public function __construct(private ComplianceService $compliance, private Config $config)
    {
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonResponse::write($response, ['success' => true] + $this->compliance->get());
    }

    public function updateEstablishment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, ['success' => true, 'establishment' => $this->compliance->updateEstablishment($payload, (int) $user['id'])]);
    }

    public function updatePoint(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, ['success' => true, 'compliance' => $this->compliance->updatePoint((int) $arguments['id'], $payload, (int) $user['id'])]);
    }

    public function preflight(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonResponse::write($response, ['success' => true] + $this->compliance->preflight());
    }
}
