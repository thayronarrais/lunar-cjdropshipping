<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Thayron\CjDropshipping\Webhooks\SignatureVerifier;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class WebhookControllerTest extends TestCase
{
    use CreatesLunarBaseline;

    private ProductLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        Queue::fake();
        FakeCj::install($this->app);

        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'tax_class_id' => $this->taxClass->id]);
        $this->link = ProductLink::create(['cj_product_id' => 'p-100', 'lunar_product_id' => $product->id, 'markup_percent' => '100', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []]);
        VariantLink::create(['cj_variant_id' => 'v-1', 'cj_product_link_id' => $this->link->id, 'lunar_variant_id' => $variant->id, 'stock' => 1]);
    }

    public function test_queues_a_sync_for_a_linked_product(): void
    {
        $this->postWebhook(['messageId' => 'm-1', 'type' => 'PRODUCT', 'messageType' => 'UPDATE', 'params' => ['pid' => 'p-100']])
            ->assertOk()
            ->assertExactJson(['status' => 'queued']);

        Queue::assertPushed(SyncProductJob::class, fn (SyncProductJob $job) => $job->link->is($this->link));
    }

    public function test_resolves_the_product_from_a_variant_id(): void
    {
        $this->postWebhook(['messageId' => 'm-2', 'type' => 'STOCK', 'messageType' => 'UPDATE', 'params' => ['vid' => 'v-1']])
            ->assertExactJson(['status' => 'queued']);

        Queue::assertPushed(SyncProductJob::class, 1);
    }

    public function test_ignores_duplicate_messages(): void
    {
        $payload = ['messageId' => 'm-3', 'type' => 'VARIANT', 'messageType' => 'UPDATE', 'params' => ['productId' => 'p-100']];

        $this->postWebhook($payload)->assertExactJson(['status' => 'queued']);
        $this->postWebhook($payload)->assertExactJson(['status' => 'duplicate']);

        Queue::assertPushed(SyncProductJob::class, 1);
    }

    public function test_ignores_unlinked_products_and_other_event_types(): void
    {
        $this->postWebhook(['messageId' => 'm-4', 'type' => 'PRODUCT', 'messageType' => 'UPDATE', 'params' => ['pid' => 'unknown']])
            ->assertExactJson(['status' => 'ignored']);
        $this->postWebhook(['messageId' => 'm-5', 'type' => 'ORDER', 'messageType' => 'UPDATE', 'params' => ['pid' => 'p-100']])
            ->assertExactJson(['status' => 'ignored']);
        $this->postWebhook(['messageId' => 'm-6', 'type' => 'SOMETHING_NEW', 'messageType' => 'INSERT', 'params' => ['pid' => 'p-100']])
            ->assertExactJson(['status' => 'ignored']);

        Queue::assertNothingPushed();
    }

    public function test_ignores_links_whose_lunar_product_is_soft_deleted(): void
    {
        $this->link->product->delete();

        $this->postWebhook(['messageId' => 'm-8', 'type' => 'PRODUCT', 'messageType' => 'UPDATE', 'params' => ['pid' => 'p-100']])
            ->assertExactJson(['status' => 'ignored']);
        $this->postWebhook(['messageId' => 'm-9', 'type' => 'STOCK', 'messageType' => 'UPDATE', 'params' => ['vid' => 'v-1']])
            ->assertExactJson(['status' => 'ignored']);

        Queue::assertNothingPushed();
    }

    public function test_rejects_invalid_signatures(): void
    {
        $body = (string) json_encode(['messageId' => 'm-7', 'type' => 'PRODUCT', 'messageType' => 'UPDATE', 'params' => ['pid' => 'p-100']]);

        $this->call('POST', '/cjdropshipping/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGN' => 'invalid'], $body)
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);
        $signature = (new SignatureVerifier)->sign($body, FakeCj::OPEN_ID);

        return $this->call('POST', '/cjdropshipping/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGN' => $signature], $body);
    }
}
