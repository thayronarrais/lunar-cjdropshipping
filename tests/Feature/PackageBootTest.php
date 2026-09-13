<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature;

use Lunar\Models\Currency;
use Lunar\Models\Product;
use Thayron\CjDropshipping\CjClient;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class PackageBootTest extends TestCase
{
    use CreatesLunarBaseline;

    public function test_merges_package_config(): void
    {
        $this->assertSame('cjdropshipping', config('lunar-cjdropshipping.queue'));
        $this->assertSame('cjdropshipping/webhook', config('lunar-cjdropshipping.webhooks.path'));
        $this->assertSame(2, config('lunar-cjdropshipping.sync.not_found_threshold'));
    }

    public function test_resolves_the_cj_client_from_the_sdk_bridge(): void
    {
        $this->assertInstanceOf(CjClient::class, $this->app->make(CjClient::class));
    }

    public function test_baseline_creates_a_eur_store_with_gbp_and_usd(): void
    {
        $this->createLunarBaseline();

        $this->assertSame('EUR', Currency::getDefault()?->code);
        $this->assertSame(3, Currency::query()->where('enabled', true)->count());

        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        $this->assertTrue($product->exists);
    }
}
