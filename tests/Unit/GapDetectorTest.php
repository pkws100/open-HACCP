<?php

declare(strict_types=1);

namespace Haccp\Tests\Unit;

use Haccp\Service\GapDetector;
use PHPUnit\Framework\TestCase;

final class GapDetectorTest extends TestCase
{
    public function testDetectsGapsWithoutAssumingSequenceStartsAtOne(): void
    {
        $gaps = (new GapDetector())->detect(
            ['fridge-1' => [1001, 1002, 1005]],
            ['fridge-1' => null],
        );

        self::assertSame([[
            'measurement_point' => 'fridge-1',
            'from_sequence' => 1003,
            'to_sequence' => 1004,
        ]], $gaps);
    }

    public function testContinuesFromPreviouslyStoredSequence(): void
    {
        $gaps = (new GapDetector())->detect(['fridge-1' => [10]], ['fridge-1' => 7]);

        self::assertSame(8, $gaps[0]['from_sequence']);
        self::assertSame(9, $gaps[0]['to_sequence']);
    }
}
