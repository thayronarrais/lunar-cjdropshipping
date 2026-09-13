<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Console;

use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class AddToMyProductsCommandTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cjdropshipping.max_retries' => 0]);
        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
    }

    public function test_adds_products_and_reports_totals(): void
    {
        $this->link('p-1');
        $this->link('p-2');
        $trashed = $this->link('p-3');
        $trashed->product->delete();

        $this->cj->success(true)->error(1600000, 'The product has been added to My Products.');

        $this->artisan('cj:add-to-my-products')
            ->expectsOutput('Added 1, not added 0, failed 1.')
            ->assertSuccessful();

        $this->assertCount(2, $this->cj->requests());
        $this->assertSame(['product/addToMyProduct', 'product/addToMyProduct'], $this->cj->paths());
    }

    public function test_product_option_limits_to_a_single_link(): void
    {
        $this->link('p-1');
        $this->link('p-2');

        $this->cj->success(true);

        $this->artisan('cj:add-to-my-products --product=p-1')
            ->expectsOutput('Added 1, not added 0, failed 0.')
            ->assertSuccessful();

        $this->assertCount(1, $this->cj->requests());
        $this->assertSame(['productId' => 'p-1'], $this->cj->jsonAt(0));
    }

    public function test_returns_failure_when_every_attempt_fails(): void
    {
        $this->link('p-1');
        $this->link('p-2');

        $this->cj->error(1600000, 'System busy')->error(1600000, 'System busy');

        $this->artisan('cj:add-to-my-products')
            ->expectsOutput('Added 0, not added 0, failed 2.')
            ->assertFailed();
    }

    private function link(string $cjProductId): ProductLink
    {
        return ProductLink::create([
            'cj_product_id' => $cjProductId,
            'lunar_product_id' => Product::factory()->create(['product_type_id' => $this->productType->id])->id,
            'markup_percent' => '0',
            'rounding' => PriceRounding::None,
            'new_cj_variant_ids' => [],
        ]);
    }
}
