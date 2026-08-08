<?php

declare(strict_types=1);

namespace Haccp\Demo;

use JsonException;
use RuntimeException;

final readonly class DemoStateStore
{
    public function __construct(private string $path)
    {
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return ['schema_version' => 1, 'devices' => []];
        }

        try {
            $state = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Demo state is not valid JSON.', 0, $exception);
        }

        if (!is_array($state) || ($state['schema_version'] ?? null) !== 1 || !is_array($state['devices'] ?? null)) {
            throw new RuntimeException('Demo state has an unsupported structure.');
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    public function save(array $state): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Demo state directory could not be created.');
        }

        $temporary = tempnam($directory, '.state-');
        if ($temporary === false) {
            throw new RuntimeException('Temporary demo state could not be created.');
        }

        try {
            chmod($temporary, 0600);
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $this->path)) {
                throw new RuntimeException('Demo state could not be persisted.');
            }
            chmod($this->path, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
