<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Sync;

use Illuminate\Support\Facades\Queue;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class SyncCommandTest extends TestCase
{
    use CreatesLunarBaseline;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createLunarBaseline();
    }

    public function test_dispatches_only_stale_links_by_default(): void
    {
        $stale = $this->link('p-1', now()->subHours(7));
        $never = $this->link('p-2', null);
        $this->link('p-3', now()->subHour());

        $this->artisan('cj:sync')->expectsOutput('Dispatched 2 sync job(s).')->assertSuccessful();

        Queue::assertPushed(SyncProductJob::class, 2);
        Queue::assertPushed(SyncProductJob::class, fn (SyncProductJob $job) => $job->link->is($stale));
        Queue::assertPushed(SyncProductJob::class, fn (SyncProductJob $job) => $job->link->is($never));
    }

    public function test_all_option_dispatches_every_link(): void
    {
        $this->link('p-1', now());
        $this->link('p-2', now());

        $this->artisan('cj:sync --all')->expectsOutput('Dispatched 2 sync job(s).')->assertSuccessful();
    }

    public function test_product_option_dispatches_a_single_link(): void
    {
        $this->link('p-1', now());
        $target = $this->link('p-2', now());

        $this->artisan('cj:sync --product=p-2')->expectsOutput('Dispatched 1 sync job(s).')->assertSuccessful();

        Queue::assertPushed(SyncProductJob::class, fn (SyncProductJob $job) => $job->link->is($target));
    }

    private function link(string $cjProductId, ?\DateTimeInterface $lastSyncedAt): ProductLink
    {
        return ProductLink::create([
            'cj_product_id' => $cjProductId,
            'lunar_product_id' => Product::factory()->create(['product_type_id' => $this->productType->id])->id,
            'markup_percent' => '0',
            'rounding' => PriceRounding::None,
            'new_cj_variant_ids' => [],
            'last_synced_at' => $lastSyncedAt,
        ]);
    }
}
