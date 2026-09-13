<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Support;

use Carbon\CarbonImmutable;

final class QuotaDelay
{
    /**
     * Seconds until 00:05 UTC tomorrow, when the CJ daily quota has reset.
     */
    public static function seconds(): int
    {
        $now = CarbonImmutable::now('UTC');

        return max(60, (int) $now->diffInSeconds($now->addDay()->startOfDay()->addMinutes(5), true));
    }
}
