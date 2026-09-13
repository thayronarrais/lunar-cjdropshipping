<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Import;

use Lunar\Models\ProductVariant;
use Thayron\LunarCjDropshipping\Actions\ImportNewVariants;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\Support\ImportsFixtureProduct;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ImportNewVariantsTest extends TestCase
{
    use CreatesLunarBaseline;
    use ImportsFixtureProduct;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
    }

    public function test_creates_pending_new_variants_and_clears_the_list(): void
    {
        $link = $this->importFixtureProduct();
        $link->forceFill(['new_cj_variant_ids' => ['v-3']])->save();

        $detail = FakeCj::data('product-detail');
        $detail['variants'][] = [
            'vid' => 'v-3', 'pid' => 'p-100', 'variantSku' => 'CJ-CASE-RED-S', 'variantKey' => 'Red-S',
            'variantWeight' => 100, 'variantSellPrice' => '10.00',
        ];
        $stock = FakeCj::data('stock-by-pid');
        $stock['variantInventories'][] = ['vid' => 'v-3', 'inventory' => [['countryCode' => 'CN', 'totalInventory' => 9]]];
        $this->cj->success($detail)->success($stock);

        $created = app(ImportNewVariants::class)->handle($link);

        $this->assertSame(1, $created);
        $red = ProductVariant::query()->where('sku', 'CJ-CASE-RED-S')->sole();
        $this->assertSame($link->lunar_product_id, $red->product_id);
        $this->assertSame(9, (int) $red->stock);
        $this->assertSame(['Red', 'S'], $red->values->sortBy('product_option_id')->map(fn ($value) => $value->name['en'])->values()->all());
        $this->assertSame(1, VariantLink::query()->where('cj_variant_id', 'v-3')->count());
        $this->assertSame([], $link->fresh()->new_cj_variant_ids);
        $this->assertSame(3, $link->product->variants()->count());
    }

    public function test_does_nothing_without_new_variants(): void
    {
        $link = $this->importFixtureProduct();

        $this->assertSame(0, app(ImportNewVariants::class)->handle($link));
        $this->assertCount(2, $this->cj->requests());
    }
}
