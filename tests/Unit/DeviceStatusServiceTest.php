<?php

declare(strict_types=1);

namespace Haccp\Tests\Unit;

use Haccp\Service\DeviceStatusService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceStatusServiceTest extends TestCase
{
    #[DataProvider('batteryCases')]
    public function testBatteryStates(?int $millivolts, string $expected): void
    {
        self::assertSame($expected, (new DeviceStatusService())->battery($millivolts, 5600, 6000));
    }

    /** @return list<array{?int, string}> */
    public static function batteryCases(): array
    {
        return [[null, 'unknown'], [5599, 'low'], [5600, 'medium'], [5999, 'medium'], [6000, 'full']];
    }

    #[DataProvider('wifiCases')]
    public function testWifiBars(?int $rssi, ?int $expected): void
    {
        self::assertSame($expected, (new DeviceStatusService())->wifiBars($rssi));
    }

    /** @return list<array{?int, ?int}> */
    public static function wifiCases(): array
    {
        return [[null, null], [-55, 4], [-56, 3], [-67, 3], [-68, 2], [-75, 2], [-76, 1]];
    }

    public function testAlarmBoundariesAreInclusiveAndWorstStateIsSelected(): void
    {
        $service = new DeviceStatusService();
        self::assertSame('normal', $service->alarm(true, 2.0, 7.0, 2.0));
        self::assertSame('normal', $service->alarm(true, 2.0, 7.0, 7.0));
        self::assertSame('below_min', $service->alarm(true, 2.0, 7.0, 1.99));
        self::assertSame('above_max', $service->alarm(true, 2.0, 7.0, 7.01));
        self::assertSame('disabled', $service->alarm(false, 2.0, 7.0, 99.0));
        self::assertSame('no_data', $service->alarm(true, 2.0, 7.0, null));
        self::assertSame('above_max', $service->worstAlarm(['normal', 'above_max', 'no_data']));
    }
}
