<?php

declare(strict_types=1);

namespace Haccp\Support;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerFactory
{
    public static function create(string $environment): Logger
    {
        $handler = new StreamHandler('php://stderr', $environment === 'test' ? Level::Warning : Level::Info);
        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true));

        return new Logger('haccp-monitor-backend', [$handler]);
    }
}
