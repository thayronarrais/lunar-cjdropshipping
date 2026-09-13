<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Import;

use Lunar\Models\Collection;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Thayron\LunarCjDropshipping\Actions\ImportProduct;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Exceptions\PricingException;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ImportProductTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cj = FakeCj::install($this->app);
    }

    public function test_imports_a_draft_product_with_options_variants_prices_and_stock(): void
    {
        $this->createLunarBaseline();
        $collection = Collection::factory()->create();
        $candidate = $this->candidate(['country_code' => 'US', 'collection_id' => $collection->id]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        $result = app(ImportProduct::class)->handle($candidate);

        $this->assertSame(['product/query', 'product/stock/getInventoryByPid'], $this->cj->paths());

        $product = Product::query()->sole();
        $this->assertSame('draft', $product->status);
        $this->assertSame('Magnetic Phone Case', $product->translateAttribute('name', 'en'));
        $this->assertSame('Magnetic Phone Case', $product->translateAttribute('name', 'fr'));
        $this->assertSame('<p>Strong magnets</p>', $product->translateAttribute('description', 'en'));
        $this->assertTrue($product->collections->contains($collection));
        $this->assertTrue($product->channels->contains($this->channel));
        $this->assertSame(['color', 'size'], $product->productOptions()->orderByPivot('position')->pluck('handle')->all());

        $black = ProductVariant::query()->where('sku', 'CJ-CASE-BLK-XL')->sole();
        $navy = ProductVariant::query()->where('sku', 'CJ-CASE-NVY-M')->sole();
        $this->assertSame(7, (int) $black->stock);
        $this->assertSame(0, (int) $navy->stock);
        $this->assertSame('in_stock', $black->purchasable);
        $this->assertEqualsWithDelta(1.58, (float) $black->weight_value, 0.0001);
        $this->assertSame('kg', $black->weight_unit);
        $this->assertEqualsWithDelta(30.0, (float) $black->length_value, 0.0001);
        $this->assertSame('cm', $black->length_unit);
        $this->assertSame(['Black', 'XL'], $black->values->sortBy('product_option_id')->map(fn ($value) => $value->name['en'])->values()->all());
        $this->assertSame(['Navy Blue', 'M'], $navy->values->sortBy('product_option_id')->map(fn ($value) => $value->name['en'])->values()->all());

        $this->assertSame(['EUR' => 1890, 'GBP' => 1590, 'USD' => 2090], $this->prices($black));
        $this->assertSame(['EUR' => 1590, 'GBP' => 1290, 'USD' => 1690], $this->prices($navy));

        $link = ProductLink::query()->sole();
        $this->assertTrue($result->link->is($link));
        $this->assertSame($product->id, $link->lunar_product_id);
        $this->assertSame('100.00', $link->markup_percent);
        $this->assertSame(PriceRounding::Ends90, $link->rounding);
        $this->assertSame('US', $link->country_code);
        $this->assertSame(CjProductStatus::Active, $link->cj_status);
        $this->assertSame(['https://cf.cjdropshipping.com/case-1.jpg', 'https://cf.cjdropshipping.com/case-2.jpg'], $result->imageUrls);

        $variantLink = VariantLink::query()->where('cj_variant_id', 'v-1')->sole();
        $this->assertSame($black->id, $variantLink->lunar_variant_id);
        $this->assertSame('10.00', $variantLink->cost_usd);
        $this->assertSame(7, $variantLink->stock);

        $candidate->refresh();
        $this->assertSame(CandidateStatus::Imported, $candidate->status);
        $this->assertSame($product->id, $candidate->lunar_product_id);
    }

    public function test_uses_total_stock_when_the_rule_has_no_country(): void
    {
        $this->createLunarBaseline();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        app(ImportProduct::class)->handle($this->candidate());

        $this->assertSame(107, (int) ProductVariant::query()->where('sku', 'CJ-CASE-BLK-XL')->value('stock'));
        $this->assertSame(20, (int) ProductVariant::query()->where('sku', 'CJ-CASE-NVY-M')->value('stock'));
    }

    public function test_reuses_shared_options_and_values_across_products(): void
    {
        $this->createLunarBaseline();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');
        app(ImportProduct::class)->handle($this->candidate());

        $second = FakeCj::data('product-detail');
        $second['pid'] = 'p-300';
        $second['variants'][0]['vid'] = 'v-31';
        $second['variants'][1]['vid'] = 'v-32';
        $this->cj->success($second)->fixture('stock-by-pid');

        app(ImportProduct::class)->handle($this->candidate(cjProductId: 'p-300'));

        $this->assertSame(2, ProductOption::query()->count());
        $this->assertSame(4, ProductOptionValue::query()->count());
    }

    public function test_single_variant_products_have_no_options(): void
    {
        $this->createLunarBaseline();
        $data = FakeCj::data('product-detail');
        $data['variants'] = [$data['variants'][0]];
        $this->cj->success($data)->fixture('stock-by-pid');

        app(ImportProduct::class)->handle($this->candidate());

        $product = Product::query()->sole();
        $this->assertSame(0, $product->productOptions()->count());
        $this->assertSame(1, $product->variants()->count());
    }

    public function test_is_idempotent_when_the_product_is_already_linked(): void
    {
        $this->createLunarBaseline();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');
        $first = app(ImportProduct::class)->handle($this->candidate());

        $again = app(ImportProduct::class)->handle($this->candidate());

        $this->assertTrue($again->link->is($first->link));
        $this->assertSame([], $again->imageUrls);
        $this->assertSame(1, Product::query()->count());
        $this->assertCount(2, $this->cj->requests());
    }

    public function test_reimports_when_the_linked_product_was_soft_deleted(): void
    {
        $this->createLunarBaseline();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');
        $first = app(ImportProduct::class)->handle($this->candidate());
        $first->link->product->delete();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        $again = app(ImportProduct::class)->handle($this->candidate());

        $this->assertFalse($again->link->is($first->link));
        $this->assertNull(ProductLink::query()->find($first->link->id));
        $this->assertSame(0, VariantLink::query()->where('cj_product_link_id', $first->link->id)->count());
        $this->assertSame(1, Product::query()->count());
        $this->assertNotSame($first->link->lunar_product_id, $again->link->lunar_product_id);
        $this->assertSame($again->link->lunar_product_id, Product::query()->sole()->id);
        $this->assertSame(2, $again->link->variantLinks()->count());
        $this->assertNotSame([], $again->imageUrls);
        $this->assertSame($again->link->lunar_product_id, $this->candidate()->fresh()->lunar_product_id);
    }

    public function test_rolls_back_everything_when_pricing_fails(): void
    {
        $this->createLunarBaseline(withUsd: false);
        config(['lunar-cjdropshipping.pricing.usd_to_default_rate' => null]);
        $candidate = $this->candidate();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        try {
            app(ImportProduct::class)->handle($candidate);
            $this->fail('Expected PricingException.');
        } catch (PricingException) {
            $this->assertSame(0, Product::withTrashed()->count());
            $this->assertSame(0, ProductLink::query()->count());
            $this->assertSame(0, VariantLink::query()->count());
        }
    }

    public function test_requires_a_default_tax_class(): void
    {
        $this->createLunarBaseline();
        TaxClass::query()->update(['default' => false]);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('default tax class');

        app(ImportProduct::class)->handle($this->candidate());
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

    /**
     * @param  array<string, mixed>  $ruleAttributes
     */
    private function candidate(array $ruleAttributes = [], string $cjProductId = 'p-100'): Candidate
    {
        $rule = ImportRule::query()->firstOrCreate(['name' => 'Rule'], [
            'keyword' => 'case',
            'markup_percent' => '100',
            'rounding' => PriceRounding::Ends90,
            'product_type_id' => $this->productType->id,
            ...$ruleAttributes,
        ]);

        return Candidate::query()->firstOrCreate(
            ['import_rule_id' => $rule->id, 'cj_product_id' => $cjProductId],
            ['name' => 'Magnetic Phone Case', 'status' => CandidateStatus::Approved, 'payload' => [], 'discovered_at' => now()],
        );
    }
}
