<?php

declare(strict_types=1);

namespace Haccp\Tests\Unit;

use Haccp\Service\ApiKeyService;
use PHPUnit\Framework\TestCase;

final class ApiKeyServiceTest extends TestCase
{
    public function testGeneratesAndVerifiesKeysWithoutStoringPlaintext(): void
    {
        $service = new ApiKeyService(str_repeat('a', 64));
        $key = $service->generate();
        $hash = $service->hash($key);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertNotSame($key, $hash);
        self::assertTrue($service->verify($key, $hash));
        self::assertFalse($service->verify(str_repeat('0', 64), $hash));
    }
}
