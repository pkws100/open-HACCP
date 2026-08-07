<?php

declare(strict_types=1);

namespace Haccp;

use RuntimeException;

final readonly class Config
{
    public function __construct(
        public string $environment,
        public bool $debug,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public string $deviceKeyPepper,
        public int $maxRequestBytes = 262_144,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $required = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DEVICE_API_KEY_PEPPER'];
        foreach ($required as $name) {
            if (getenv($name) === false || getenv($name) === '') {
                throw new RuntimeException(sprintf('Required environment variable %s is missing.', $name));
            }
        }

        $pepper = (string) getenv('DEVICE_API_KEY_PEPPER');
        if (strlen($pepper) < 32) {
            throw new RuntimeException('DEVICE_API_KEY_PEPPER must contain at least 32 characters.');
        }

        return new self(
            environment: (string) (getenv('APP_ENV') ?: 'production'),
            debug: filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL),
            dbHost: (string) getenv('DB_HOST'),
            dbPort: (int) (getenv('DB_PORT') ?: 3306),
            dbName: (string) getenv('DB_DATABASE'),
            dbUser: (string) getenv('DB_USERNAME'),
            dbPassword: (string) getenv('DB_PASSWORD'),
            deviceKeyPepper: $pepper,
        );
    }
}
