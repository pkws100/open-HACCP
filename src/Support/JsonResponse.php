<?php

declare(strict_types=1);

namespace Haccp\Support;

use Psr\Http\Message\ResponseInterface;

final class JsonResponse
{
    /** @param array<string, mixed> $payload */
    public static function write(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($json);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
