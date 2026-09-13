<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Thayron\CjDropshipping\Data\ProductInventory;
use Thayron\LunarCjDropshipping\Mapping\StockResolver;

final class StockResolverTest extends TestCase
{
    public function test_sums_stock_and_filters_by_country(): void
    {
        $inventory = ProductInventory::fromArray([
            'inventories' => [],
            'variantInventories' => [
                ['vid' => 'v1', 'inventory' => [
                    ['countryCode' => 'CN', 'totalInventory' => 100],
                    ['countryCode' => 'US', 'totalInventory' => 7],
                    ['countryCode' => 'us', 'totalInventory' => 3],
                ]],
                ['vid' => '1796078021431009280', 'inventory' => [['countryCode' => 'CN', 'totalInventory' => 5]]],
            ],
        ]);
        $resolver = new StockResolver;

        $this->assertSame(110, $resolver->forVariant($inventory, 'v1', null));
        $this->assertSame(10, $resolver->forVariant($inventory, 'v1', 'US'));
        $this->assertSame(5, $resolver->forVariant($inventory, '1796078021431009280', null));
        $this->assertSame(0, $resolver->forVariant($inventory, 'missing', null));
    }
}
