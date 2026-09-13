<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Livewire\Livewire;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Filament\Resources\ProductLinkResource\Pages\ListProductLinks;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\FilamentTestCase;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;

final class ProductLinkMarginFilterTest extends FilamentTestCase
{
    use CreatesLunarBaseline;

    public function test_filters_links_with_margin_at_risk(): void
    {
        $this->createLunarBaseline();
        $this->actingAsStaff();
        $risky = $this->link('p-1', true);
        $healthy = $this->link('p-2', false);

        Livewire::test(ListProductLinks::class)
            ->assertCanSeeTableRecords([$risky, $healthy])
            ->assertSee(__('lunar-cjdropshipping::admin.links.margin.at_risk'))
            ->filterTable('margin_at_risk')
            ->assertCanSeeTableRecords([$risky])
            ->assertCanNotSeeTableRecords([$healthy]);
    }

    private function link(string $cjProductId, bool $atRisk): ProductLink
    {
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);

        return ProductLink::create([
            'cj_product_id' => $cjProductId, 'lunar_product_id' => $product->id, 'markup_percent' => '0',
            'rounding' => PriceRounding::None, 'new_cj_variant_ids' => [], 'price_locked' => true, 'margin_at_risk' => $atRisk,
        ]);
    }
}
