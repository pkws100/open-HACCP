<?php

declare(strict_types=1);

namespace Haccp\Demo;

use JsonException;

final readonly class DemoHttpClient
{
    public function __construct(private string $baseUrl)
    {
    }

    /** @param array<string, mixed> $payload @return array{status: int, json: array<string, mixed>|null} */
    public function post(string $path, string $deviceUid, string $deviceKey, array $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Device-ID: ' . $deviceUid,
                    'X-Device-Key: ' . $deviceKey,
                ]),
                'content' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'ignore_errors' => true,
                'timeout' => 20,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $body = @file_get_contents(rtrim($this->baseUrl, '/') . $path, false, $context);
        $headers = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $match);
        $status = isset($match[1]) ? (int) $match[1] : 0;
        if ($body === false || $body === '') {
            return ['status' => $status, 'json' => null];
        }

        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['status' => $status, 'json' => null];
        }

        return ['status' => $status, 'json' => is_array($json) ? $json : null];
    }
}
