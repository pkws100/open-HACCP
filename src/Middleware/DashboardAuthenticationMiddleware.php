<?php

declare(strict_types=1);

namespace Haccp\Middleware;

use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class DashboardAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private string $username, private string $password)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        $credentials = null;
        if (str_starts_with($authorization, 'Basic ')) {
            $decoded = base64_decode(substr($authorization, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                $credentials = explode(':', $decoded, 2);
            }
        }

        if ($credentials === null
            || !hash_equals($this->username, $credentials[0])
            || !hash_equals($this->password, $credentials[1])) {
            return JsonResponse::write(new Response(), [
                'success' => false,
                'error' => [
                    'code' => 'DASHBOARD_AUTHENTICATION_REQUIRED',
                    'message' => 'Dashboard authentication is required',
                ],
            ], 401)->withHeader('WWW-Authenticate', 'Basic realm="Open HACCP Monitor", charset="UTF-8"');
        }

        return $handler->handle($request);
    }
}
