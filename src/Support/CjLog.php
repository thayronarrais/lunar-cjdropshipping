<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

final class CjLog
{
    public static function channel(): LoggerInterface
    {
        $channel = config('lunar-cjdropshipping.log_channel');

        return Log::channel(is_string($channel) && $channel !== '' ? $channel : null);
    }
}
