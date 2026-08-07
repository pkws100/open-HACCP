<?php

declare(strict_types=1);

namespace Haccp\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    private DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->utc);
    }

    public function database(DateTimeImmutable $value): string
    {
        return $value->setTimezone($this->utc)->format('Y-m-d H:i:s.u');
    }

    public function api(DateTimeImmutable $value): string
    {
        return $value->setTimezone($this->utc)->format('Y-m-d\TH:i:s\Z');
    }
}
