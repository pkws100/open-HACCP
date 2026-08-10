<?php

declare(strict_types=1);

namespace Haccp\Controller;

use Haccp\Config;
use Haccp\Service\PhotoService;
use Haccp\Support\JsonBody;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

final readonly class PhotoController
{
    public function __construct(private PhotoService $photos, private Config $config)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return JsonResponse::write($response, $this->photos->list((int) $arguments['id']))
            ->withHeader('Cache-Control', 'no-store, private');
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $contentLength = $request->getHeaderLine('Content-Length');
        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $this->config->maxPhotoUploadBytes + 65_536) {
            throw new \Haccp\Api\ApiException(413, 'PHOTO_TOO_LARGE', 'Das Foto überschreitet die zulässige Größe von 12 MiB.');
        }
        $upload = $request->getUploadedFiles()['photo'] ?? null;
        if (!$upload instanceof \Psr\Http\Message\UploadedFileInterface) {
            throw new \Haccp\Api\ApiException(422, 'INVALID_IMAGE', 'Das Multipart-Feld photo ist erforderlich.');
        }
        $result = $this->photos->upload((int) $arguments['id'], $upload, $request->getAttribute('dashboard_user'));

        return JsonResponse::write($response, $result, 201)->withHeader('Cache-Control', 'no-store, private');
    }

    public function image(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $file = $this->photos->file((string) $arguments['photo_id'], (string) $arguments['variant']);
        $handle = @fopen((string) $file['path'], 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Photo file could not be opened.');
        }

        return $response->withBody(new Stream($handle))
            ->withHeader('Content-Type', (string) $file['mime_type'])
            ->withHeader('Content-Length', (string) $file['size'])
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'private, max-age=86400, immutable')
            ->withHeader('ETag', '"' . (string) $file['sha256'] . '"')
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', strtotime((string) $file['created_at'])) . ' GMT')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $payload = JsonBody::decode($request, $this->config->maxRequestBytes);
        $password = is_string($payload->current_password ?? null) ? $payload->current_password : '';

        return JsonResponse::write($response, $this->photos->delete(
            (string) $arguments['photo_id'],
            $password,
            $request->getAttribute('dashboard_user'),
        ))->withHeader('Cache-Control', 'no-store, private');
    }
}
