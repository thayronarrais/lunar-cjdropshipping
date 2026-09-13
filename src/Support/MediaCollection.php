<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Support;

final class MediaCollection
{
    public static function name(): string
    {
        $configured = config('lunar-cjdropshipping.media.collection');

        return is_string($configured) && $configured !== '' ? $configured : (string) config('lunar.media.collection', 'images');
    }
}
