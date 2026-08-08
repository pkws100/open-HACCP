<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\EventWorkflowService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class EventController
{
    public function __construct(private EventWorkflowService $events, private Config $config)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $state = isset($query['state']) && is_string($query['state']) ? $query['state'] : null;
        $device = isset($query['device']) && is_string($query['device']) ? $query['device'] : null;
        $days = isset($query['days']) && is_numeric($query['days']) ? (int) $query['days'] : 30;

        return JsonResponse::write($response, ['success' => true] + $this->events->list($state, $device, $days));
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return JsonResponse::write($response, ['success' => true] + $this->events->detail((int) $arguments['id']));
    }

    public function acknowledge(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, ['success' => true] + $this->events->acknowledge((int) $arguments['id'], (int) $user['id']));
    }

    public function action(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, ['success' => true] + $this->events->action((int) $arguments['id'], $payload, (int) $user['id']), 201);
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, ['success' => true] + $this->events->verify((int) $arguments['id'], $payload, $user));
    }

    public function revise(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, ['success' => true] + $this->events->reviseAction((int) $arguments['id'], $payload, (int) $user['id']));
    }

    public function batteryReplaced(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, $this->events->batteryReplaced((string) $arguments['device_uid'], $payload, (int) $user['id']), 201);
    }
}
