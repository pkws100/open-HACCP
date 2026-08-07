<?php

declare(strict_types=1);

namespace Haccp\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $provided = $request->getHeaderLine('X-Request-ID');
        $requestId = preg_match('/^[A-Za-z0-9._-]{1,64}$/', $provided) === 1
            ? $provided
            : bin2hex(random_bytes(16));

        $response = $handler->handle($request->withAttribute('request_id', $requestId));

        return $response->withHeader('X-Request-ID', $requestId);
    }
}
