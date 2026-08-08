<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\UserService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class UserController
{
    public function __construct(private UserService $users, private Config $config)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonResponse::write($response, ['success' => true, 'users' => $this->users->list()]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, ['success' => true] + $this->users->create($payload, (int) $user['id']), 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, ['success' => true, 'user' => $this->users->update((int) $arguments['id'], $payload, (int) $user['id'])]);
    }

    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, [
            'success' => true,
            'temporary_password' => $this->users->resetPassword((int) $arguments['id'], (int) $user['id']),
        ])->withHeader('Cache-Control', 'no-store');
    }
}
