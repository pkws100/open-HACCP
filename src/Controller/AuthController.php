<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\AuthService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Haccp\Support\SessionCookie;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuthController
{
    public function __construct(private AuthService $auth, private Config $config)
    {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $result = $this->auth->login(
            is_string($payload->username ?? null) ? $payload->username : '',
            is_string($payload->password ?? null) ? $payload->password : '',
        );
        $secure = $this->secureCookie();

        return JsonResponse::write($response, [
            'success' => true,
            'user' => $result['user'],
            'csrf_token' => $result['csrf_token'],
        ])->withHeader('Set-Cookie', SessionCookie::value($result['token'], $secure))
            ->withHeader('Cache-Control', 'no-store');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('dashboard_user');
        $this->auth->logout($request->getAttribute('session_token'), is_array($user) ? (int) $user['id'] : null);
        $secure = $this->secureCookie();

        return JsonResponse::write($response, ['success' => true])
            ->withHeader('Set-Cookie', SessionCookie::clear($secure));
    }

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, [
            'success' => true,
            'user' => $this->auth->publicUser($user),
            'csrf_token' => $user['csrf_token'],
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function password(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');
        $this->auth->changePassword(
            $user,
            is_string($payload->current_password ?? null) ? $payload->current_password : '',
            is_string($payload->new_password ?? null) ? $payload->new_password : '',
        );
        $secure = $this->secureCookie();

        return JsonResponse::write($response, ['success' => true, 'reauthentication_required' => true])
            ->withHeader('Set-Cookie', SessionCookie::clear($secure));
    }

    private function secureCookie(): bool
    {
        return in_array($this->config->environment, ['production', 'staging'], true);
    }
}
