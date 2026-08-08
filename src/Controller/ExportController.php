<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\ExportService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

final readonly class ExportController
{
    public function __construct(private ExportService $exports, private Config $config)
    {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $user = $request->getAttribute('dashboard_user');

        return JsonResponse::write($response, ['success' => true] + $this->exports->create($payload, $user), 202);
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonResponse::write($response, ['success' => true, 'jobs' => $this->exports->list($request->getAttribute('dashboard_user'))]);
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return JsonResponse::write($response, ['success' => true, 'job' => $this->exports->get((string) $arguments['id'], $request->getAttribute('dashboard_user'))]);
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $job = $this->exports->download((string) $arguments['id'], $request->getAttribute('dashboard_user'));
        $handle = @fopen((string) $job['file_path'], 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Export file could not be opened.');
        }

        return $response->withBody(new Stream($handle))
            ->withHeader('Content-Type', (string) $job['mime_type'])
            ->withHeader('Content-Length', (string) $job['file_size'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename((string) $job['file_name']) . '"')
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
