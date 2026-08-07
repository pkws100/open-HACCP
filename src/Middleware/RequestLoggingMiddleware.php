<?php

declare(strict_types=1);

namespace Haccp\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $started = hrtime(true);
        $response = $handler->handle($request);
        $durationMs = (hrtime(true) - $started) / 1_000_000;

        $this->logger->info('http_request', [
            'timestamp' => gmdate('c'),
            'request_id' => $request->getAttribute('request_id'),
            'device_uid' => $request->getHeaderLine('X-Device-ID') ?: null,
            'endpoint' => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'http_status' => $response->getStatusCode(),
            'duration_ms' => round($durationMs, 2),
        ]);

        return $response;
    }
}
