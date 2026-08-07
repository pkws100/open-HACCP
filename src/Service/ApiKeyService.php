<?php

declare(strict_types=1);

namespace Haccp\Service;

final readonly class ApiKeyService
{
    public function __construct(private string $pepper)
    {
    }

    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hash(string $key): string
    {
        return hash_hmac('sha256', $key, $this->pepper);
    }

    public function verify(string $key, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($key));
    }
}
