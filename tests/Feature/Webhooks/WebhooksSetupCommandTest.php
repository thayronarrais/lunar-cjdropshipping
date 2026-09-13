<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\URL;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class WebhooksSetupCommandTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
    }

    public function test_configures_topics_and_subscribes_linked_products(): void
    {
        URL::forceRootUrl('https://shop.example.com');
        ProductLink::create(['cj_product_id' => 'p-100', 'lunar_product_id' => Product::factory()->create(['product_type_id' => $this->productType->id])->id, 'markup_percent' => '0', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []]);
        $this->cj->success(true)->success(['successProductIds' => ['p-100'], 'failProductIds' => [], 'subscribeAll' => false]);

        $this->artisan('cj:webhooks:setup')
            ->expectsOutput('Webhook URL: https://shop.example.com/cjdropshipping/webhook')
            ->expectsOutput('Subscribed 1 product(s), 0 failed.')
            ->assertSuccessful();

        $this->assertSame(['webhook/set', 'webhook/product/subscribe'], $this->cj->paths());
        $settings = $this->cj->jsonAt(0);
        $this->assertSame(['type' => 'ENABLE', 'callbackUrls' => ['https://shop.example.com/cjdropshipping/webhook']], $settings['product']);
        $this->assertSame('ENABLE', $settings['stock']['type']);
        $this->assertSame('CANCEL', $settings['order']['type']);
        $this->assertSame(['productIds' => ['p-100']], $this->cj->jsonAt(1));
    }

    public function test_fails_without_a_public_https_url(): void
    {
        URL::forceRootUrl('http://localhost');

        $this->artisan('cj:webhooks:setup')->assertFailed();

        $this->assertSame([], $this->cj->requests());
    }
}
