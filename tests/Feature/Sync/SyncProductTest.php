<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Sync;

use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\LunarCjDropshipping\Actions\SyncProduct;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\Support\ImportsFixtureProduct;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class SyncProductTest extends TestCase
{
    use CreatesLunarBaseline;
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
        $this->travel(1)->hours();
    }

    public function test_updates_stock_and_recalculates_prices_when_cost_changes(): void
    {
        $product = $this->link->product;
        $product->attribute_data->put('name', new TranslatedText(collect(['en' => new Text('Edited name')])));
        $product->save();

        $detail = FakeCj::data('product-detail');
        $detail['productNameEn'] = 'Renamed on CJ';
        $detail['variants'][0]['variantSellPrice'] = '12.00';
        $stock = FakeCj::data('stock-by-pid');
        $stock['variantInventories'][0]['inventory'] = [['countryCode' => 'CN', 'totalInventory' => 50]];
        $this->cj->success($detail)->success($stock);

        app(SyncProduct::class)->handle($this->link);

        $black = $this->variant('CJ-CASE-BLK-XL');
        $this->assertSame(50, (int) $black->stock);
        $this->assertSame(['EUR' => 2290, 'GBP' => 1890, 'USD' => 2490], $this->prices($black));
        $this->assertSame(['EUR' => 1590, 'GBP' => 1290, 'USD' => 1690], $this->prices($this->variant('CJ-CASE-NVY-M')));
        $this->assertSame('12.00', VariantLink::query()->where('cj_variant_id', 'v-1')->value('cost_usd'));
        $this->assertSame('Edited name', $product->fresh()->translateAttribute('name', 'en'));

        $link = $this->link->fresh();
        $this->assertTrue($link->last_synced_at->isSameSecond(now()));
        $this->assertNull($link->sync_error);
    }

    public function test_variant_missing_on_cj_gets_zero_stock_and_new_variants_are_only_recorded(): void
    {
        $detail = FakeCj::data('product-detail');
        $newVariant = $detail['variants'][1];
        $newVariant['vid'] = 'v-3';
        $newVariant['variantSku'] = 'CJ-CASE-RED-S';
        $newVariant['variantKey'] = 'Red-S';
        $detail['variants'] = [$detail['variants'][0], $newVariant];
        $this->cj->success($detail)->fixture('stock-by-pid');

        app(SyncProduct::class)->handle($this->link);

        $this->assertSame(0, (int) $this->variant('CJ-CASE-NVY-M')->stock);
        $this->assertSame(['v-3'], $this->link->fresh()->new_cj_variant_ids);
        $this->assertSame(0, ProductVariant::query()->where('sku', 'CJ-CASE-RED-S')->count());
    }

    public function test_marks_the_product_unavailable_after_consecutive_not_found_responses(): void
    {
        $this->cj->error(1602001, 'Product not found')->error(1602001, 'Product not found');

        app(SyncProduct::class)->handle($this->link);

        $link = $this->link->fresh();
        $this->assertSame(1, $link->not_found_count);
        $this->assertSame(CjProductStatus::Active, $link->cj_status);
        $this->assertSame(107, (int) $this->variant('CJ-CASE-BLK-XL')->stock);

        app(SyncProduct::class)->handle($link);

        $link->refresh();
        $this->assertSame(CjProductStatus::Unavailable, $link->cj_status);
        $this->assertSame(0, (int) $this->variant('CJ-CASE-BLK-XL')->stock);
        $this->assertSame(0, (int) $this->variant('CJ-CASE-NVY-M')->stock);
        $this->assertSame('draft', $link->product->status);
    }

    public function test_unavailable_action_draft_unpublishes_the_product(): void
    {
        config(['lunar-cjdropshipping.sync.unavailable_action' => 'draft', 'lunar-cjdropshipping.sync.not_found_threshold' => 1]);
        $this->link->product->update(['status' => 'published']);
        $this->cj->error(1602001, 'Product not found');

        app(SyncProduct::class)->handle($this->link);

        $this->assertSame('draft', $this->link->product->fresh()->status);
    }

    public function test_out_of_stock_action_keeps_the_product_published(): void
    {
        config(['lunar-cjdropshipping.sync.not_found_threshold' => 1]);
        $this->link->product->update(['status' => 'published']);
        $this->cj->error(1602001, 'Product not found');

        app(SyncProduct::class)->handle($this->link);

        $this->assertSame('published', $this->link->product->fresh()->status);
        $this->assertSame(CjProductStatus::Unavailable, $this->link->fresh()->cj_status);
    }

    public function test_a_successful_sync_resets_not_found_count_and_reactivates(): void
    {
        $this->link->forceFill(['not_found_count' => 1, 'cj_status' => CjProductStatus::Unavailable])->save();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        app(SyncProduct::class)->handle($this->link);

        $link = $this->link->fresh();
        $this->assertSame(0, $link->not_found_count);
        $this->assertSame(CjProductStatus::Active, $link->cj_status);
    }

    public function test_job_records_transient_errors_without_advancing_last_synced_at(): void
    {
        $before = $this->link->fresh()->last_synced_at;
        $this->cj->error(1600000, 'System busy');

        try {
            (new SyncProductJob($this->link))->handle(app(SyncProduct::class));
            $this->fail('Expected ServerException.');
        } catch (ServerException) {
            $link = $this->link->fresh();
            $this->assertStringContainsString('System busy', (string) $link->sync_error);
            $this->assertTrue($link->last_synced_at->equalTo($before));
        }
    }

    public function test_job_releases_on_quota_errors(): void
    {
        $this->cj->error(1600201, 'Daily quota exhausted');

        $job = (new SyncProductJob($this->link))->withFakeQueueInteractions();
        $job->handle(app(SyncProduct::class));

        $job->assertReleased();
    }

    public function test_sub_threshold_not_found_replaces_a_stale_sync_error(): void
    {
        $this->link->forceFill(['sync_error' => 'System busy'])->save();
        $this->cj->error(1602001, 'Product not found');

        app(SyncProduct::class)->handle($this->link);

        $link = $this->link->fresh();
        $this->assertSame('CJ product not found (1/2).', $link->sync_error);
        $this->assertSame(CjProductStatus::Active, $link->cj_status);
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
