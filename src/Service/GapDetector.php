<?php

declare(strict_types=1);

namespace Haccp\Service;

final class GapDetector
{
    /**
     * @param array<string, list<int>> $acknowledgedByPoint
     * @param array<string, int|null> $previousMaxByPoint
     * @return list<array{measurement_point: string, from_sequence: int, to_sequence: int}>
     */
    public function detect(array $acknowledgedByPoint, array $previousMaxByPoint): array
    {
        $gaps = [];
        foreach ($acknowledgedByPoint as $point => $sequences) {
            $sequences = array_values(array_unique($sequences));
            sort($sequences, SORT_NUMERIC);
            if ($sequences === []) {
                continue;
            }

            $cursor = $previousMaxByPoint[$point] ?? $sequences[0];
            foreach ($sequences as $sequence) {
                if ($sequence <= $cursor) {
                    continue;
                }
                if ($sequence > $cursor + 1) {
                    $gaps[] = [
                        'measurement_point' => $point,
                        'from_sequence' => $cursor + 1,
                        'to_sequence' => $sequence - 1,
                    ];
                }
                $cursor = $sequence;
            }
        }

        return $gaps;
    }
}
