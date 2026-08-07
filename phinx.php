<?php

declare(strict_types=1);

$database = static fn (): array => [
    'adapter' => 'mysql',
    'host' => getenv('DB_HOST') ?: 'db',
    'name' => getenv('DB_DATABASE') ?: 'haccp',
    'user' => getenv('DB_USERNAME') ?: 'haccp',
    'pass' => getenv('DB_PASSWORD') ?: '',
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
