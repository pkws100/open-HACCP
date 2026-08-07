<?php

declare(strict_types=1);

namespace Haccp\Middleware;

use Haccp\Repository\DeviceRepository;
use Haccp\Service\ApiKeyService;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class DeviceAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private DeviceRepository $devices, private ApiKeyService $keys)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uid = $request->getHeaderLine('X-Device-ID');
        $key = $request->getHeaderLine('X-Device-Key');
        $device = $uid === '' ? null : $this->devices->findByUid($uid);

        if ($device === null
            || $device->status !== 'active'
            || $key === ''
            || !$this->keys->verify($key, $device->apiKeyHash)) {
            return JsonResponse::write(new Response(), [
                'success' => false,
                'error' => [
                    'code' => 'DEVICE_AUTHENTICATION_FAILED',
                    'message' => 'Device authentication failed',
                ],
            ], 401);
        }

        return $handler->handle($request->withAttribute('device', $device));
    }
}
