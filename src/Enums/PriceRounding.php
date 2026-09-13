<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Enums;

enum PriceRounding: string
{
    case None = 'none';
    case Ends90 = 'ends_90';
    case Ends99 = 'ends_99';
    case Whole = 'whole';
}
