<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Sync;

use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Thayron\LunarCjDropshipping\Actions\SyncProduct;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesVatZone;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\Support\ImportsFixtureProduct;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class SyncLockedPriceTest extends TestCase
{
    use CreatesLunarBaseline;
    use CreatesVatZone;
    use ImportsFixtureProduct;

    private FakeCj $cj;

    private ProductLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cjdropshipping.max_retries' => 0]);
        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
        $this->link = $this->importFixtureProduct();
        $this->link->forceFill(['price_locked' => true, 'currency_code' => 'GBP'])->save();
        VariantLink::query()->update(['shipping_cost_usd' => '5.00', 'price' => '30.00']);
    }

    public function test_keeps_locked_prices_when_cost_changes(): void
    {
        $before = $this->prices($this->variant('CJ-CASE-BLK-XL'));
        $this->syncWithCost('12.00');

        $this->assertSame($before, $this->prices($this->variant('CJ-CASE-BLK-XL')));
        $this->assertSame('12.00', VariantLink::query()->where('cj_variant_id', 'v-1')->value('cost_usd'));
        $this->assertFalse($this->link->fresh()->margin_at_risk);
    }

    public function test_flags_and_clears_margin_at_risk(): void
    {
        // (30.00 + 5.00) USD = 27.55 GBP against a 30.00 GBP price: 8.18% margin, below 20%.
        $this->syncWithCost('30.00');
        $this->assertTrue($this->link->fresh()->margin_at_risk);

        $this->syncWithCost('12.00');
        $this->assertFalse($this->link->fresh()->margin_at_risk);
    }

    public function test_flags_links_whose_currency_is_missing(): void
    {
        $this->link->forceFill(['currency_code' => 'JPY'])->save();

        $this->syncWithCost('12.00');

        $this->assertTrue($this->link->fresh()->margin_at_risk);
    }

    public function test_skipped_variants_are_not_reported_as_new(): void
    {
        $this->link->forceFill(['skipped_cj_variant_ids' => ['v-9']])->save();
        $detail = FakeCj::data('product-detail');
        $detail['variants'][] = [...$detail['variants'][0], 'vid' => 'v-9', 'variantSku' => 'CJ-CASE-SKIPPED'];
        $detail['variants'][] = [...$detail['variants'][0], 'vid' => 'v-10', 'variantSku' => 'CJ-CASE-NEW'];
        $this->cj->success($detail)->fixture('stock-by-pid');

        app(SyncProduct::class)->handle($this->link->fresh());

        $this->assertSame(['v-10'], $this->link->fresh()->new_cj_variant_ids);
    }

    public function test_flags_margin_at_risk_when_destination_vat_eats_the_margin(): void
    {
        $this->createVatZone('GB', '20', inclusive: true);
        $this->link->forceFill(['ship_to_country' => 'GB'])->save();

        $this->syncWithCost('20.00');

        $this->assertTrue($this->link->fresh()->margin_at_risk);
    }

    public function test_the_same_cost_is_not_at_risk_without_a_destination(): void
    {
        $this->createVatZone('GB', '20', inclusive: true);

        $this->syncWithCost('20.00');

        $this->assertFalse($this->link->fresh()->margin_at_risk);
    }

    private function syncWithCost(string $cost): void
    {
        $detail = FakeCj::data('product-detail');
        $detail['variants'][0]['variantSellPrice'] = $cost;
        $this->cj->success($detail)->fixture('stock-by-pid');

        app(SyncProduct::class)->handle($this->link->fresh());
    }

    private function variant(string $sku): ProductVariant
    {
        return ProductVariant::query()->where('sku', $sku)->sole();
    }

    /**
     * @return array<string, int>
     */
    private function prices(ProductVariant $variant): array
    {
        return Price::query()
            ->where('priceable_type', $variant->getMorphClass())
            ->where('priceable_id', $variant->id)
            ->get()
            ->mapWithKeys(fn (Price $price) => [$price->currency->code => $price->price->value])
            ->sortKeys()
            ->all();
    }
}
