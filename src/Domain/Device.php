<?php

declare(strict_types=1);

namespace Haccp\Domain;

final readonly class Device
{
    public function __construct(
        public int $id,
        public string $uid,
        public string $name,
        public string $status,
        public string $apiKeyHash,
    ) {
    }
}
