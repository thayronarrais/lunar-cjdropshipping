<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Mapping;

use Thayron\CjDropshipping\Data\ProductInventory;

final class StockResolver
{
    public function forVariant(ProductInventory $inventory, string $variantId, ?string $countryCode): int
    {
        $total = 0;

        foreach ($inventory->forVariant($variantId) as $record) {
            if ($countryCode !== null && strtoupper((string) $record->countryCode) !== strtoupper($countryCode)) {
                continue;
            }

            $total += max(0, $record->total);
        }

        return $total;
    }
}
