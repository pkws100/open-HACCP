<?php

declare(strict_types=1);

$environment = static function (string $name, string $default = ''): string {
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
};

$database = static fn (): array => [
    'adapter' => 'mysql',
    'host' => getenv('DB_HOST') ?: 'db',
    'name' => getenv('DB_DATABASE') ?: 'haccp',
    'user' => getenv('DB_USERNAME') ?: 'haccp',
    'pass' => $environment('DB_PASSWORD'),
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'production',
        'production' => $database(),
        'test' => $database(),
    ],
    'version_order' => 'creation',
];
