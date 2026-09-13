<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Enums;

enum CjProductStatus: string
{
    case Active = 'active';
    case Unavailable = 'unavailable';
}
