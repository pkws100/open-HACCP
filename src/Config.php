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
        public string $auditLogKey,
        public string $dashboardUsername,
        public string $dashboardPassword,
        public int $maxRequestBytes = 262_144,
        public string $publicApiBaseUrl = 'https://haccp.pow24.org',
        public string $exportPath = '/var/lib/haccp-exports',
        public string $mediaPath = '/var/lib/haccp-media',
        public int $maxPhotoUploadBytes = 12_582_912,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $required = [
            'DB_HOST',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'DEVICE_API_KEY_PEPPER',
            'AUDIT_LOG_KEY',
            'DASHBOARD_USERNAME',
            'DASHBOARD_PASSWORD',
        ];
        foreach ($required as $name) {
            if (self::environment($name) === '') {
                throw new RuntimeException(sprintf('Required environment variable %s is missing.', $name));
            }
        }

        $pepper = self::environment('DEVICE_API_KEY_PEPPER');
        if (strlen($pepper) < 32) {
            throw new RuntimeException('DEVICE_API_KEY_PEPPER must contain at least 32 characters.');
        }
        $auditLogKey = self::environment('AUDIT_LOG_KEY');
        if (strlen($auditLogKey) < 32) {
            throw new RuntimeException('AUDIT_LOG_KEY must contain at least 32 characters.');
        }
        if (hash_equals($pepper, $auditLogKey)) {
            throw new RuntimeException('AUDIT_LOG_KEY must be different from DEVICE_API_KEY_PEPPER.');
        }
        $dashboardPasswordLength = mb_strlen(self::environment('DASHBOARD_PASSWORD'));
        if ($dashboardPasswordLength < 12 || $dashboardPasswordLength > 128) {
            throw new RuntimeException('DASHBOARD_PASSWORD must contain 12 to 128 characters.');
        }
        $publicApiBaseUrl = rtrim(self::environment('PUBLIC_API_BASE_URL', 'https://haccp.pow24.org'), '/');
        if (filter_var($publicApiBaseUrl, FILTER_VALIDATE_URL) === false
            || parse_url($publicApiBaseUrl, PHP_URL_SCHEME) !== 'https'
            || !in_array(parse_url($publicApiBaseUrl, PHP_URL_PATH), [null, ''], true)
            || parse_url($publicApiBaseUrl, PHP_URL_QUERY) !== null
            || parse_url($publicApiBaseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('PUBLIC_API_BASE_URL must be a plain HTTPS URL.');
        }

        return new self(
            environment: self::environment('APP_ENV', 'production'),
            debug: filter_var(self::environment('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
            dbHost: self::environment('DB_HOST'),
            dbPort: (int) (getenv('DB_PORT') ?: 3306),
            dbName: self::environment('DB_DATABASE'),
            dbUser: self::environment('DB_USERNAME'),
            dbPassword: self::environment('DB_PASSWORD'),
            deviceKeyPepper: $pepper,
            auditLogKey: $auditLogKey,
            dashboardUsername: self::environment('DASHBOARD_USERNAME'),
            dashboardPassword: self::environment('DASHBOARD_PASSWORD'),
            publicApiBaseUrl: $publicApiBaseUrl,
            exportPath: rtrim(self::environment('EXPORT_PATH', '/var/lib/haccp-exports'), '/'),
            mediaPath: rtrim(self::environment('MEDIA_PATH', '/var/lib/haccp-media'), '/'),
            maxPhotoUploadBytes: max(1_048_576, (int) self::environment('MAX_PHOTO_UPLOAD_BYTES', '12582912')),
        );
    }

    private static function environment(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $file = getenv($name . '_FILE');
        if (is_string($file) && $file !== '' && is_readable($file)) {
            $contents = file_get_contents($file);
            if (is_string($contents)) {
                return rtrim($contents, "\r\n");
            }
        }

        return $default;
    }
}
