<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

final class CostParser
{
    /**
     * Lowest amount in a CJ price string such as "11.85", "1.20-3.50" or "8.13 -- 8.62".
     */
    public static function lowest(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        preg_match_all('/\d+(?:\.\d+)?/', $value, $matches);

        if ($matches[0] === []) {
            return null;
        }

        $lowest = array_reduce(
            $matches[0],
            fn (?string $carry, string $amount): string => $carry === null || bccomp($amount, $carry, 12) < 0 ? $amount : $carry,
        );

        return bcadd((string) $lowest, '0', 2);
    }
}
