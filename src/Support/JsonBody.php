<?php

declare(strict_types=1);

namespace Haccp\Support;

use Haccp\Api\ApiException;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class JsonBody
{
    public static function decode(ServerRequestInterface $request, int $maxBytes): stdClass
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (!str_starts_with($contentType, 'application/json')) {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json');
        }

        $contentLength = $request->getHeaderLine('Content-Length');
        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $maxBytes) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'Request body exceeds 256 KiB');
        }

        $raw = (string) $request->getBody();
        if (strlen($raw) > $maxBytes) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'Request body exceeds 256 KiB');
        }

        try {
            $payload = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'INVALID_JSON', 'Request body is not valid JSON');
        }

        if (!$payload instanceof stdClass) {
            throw new ApiException(400, 'INVALID_JSON_OBJECT', 'Request body must be a JSON object');
        }

        return $payload;
    }
}
