<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Enums;

enum UnavailableAction: string
{
    case OutOfStock = 'out_of_stock';
    case Draft = 'draft';
}
