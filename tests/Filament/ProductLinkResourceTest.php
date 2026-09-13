<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Filament\Resources\ProductLinkResource\Pages\ListProductLinks;
use Thayron\LunarCjDropshipping\Jobs\ImportNewVariantsJob;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\FilamentTestCase;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\Support\ImportsFixtureProduct;

final class ProductLinkResourceTest extends FilamentTestCase
{
    use CreatesLunarBaseline;
    use ImportsFixtureProduct;

    private FakeCj $cj;

    private ProductLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
        $this->link = $this->importFixtureProduct();
        $this->actingAsStaff();
    }

    public function test_lists_linked_products_and_filters_unavailable_ones(): void
    {
        Livewire::test(ListProductLinks::class)
            ->assertCanSeeTableRecords([$this->link])
            ->assertSee('Magnetic Phone Case');

        Livewire::test(ListProductLinks::class)
            ->filterTable('unavailable')
            ->assertCanNotSeeTableRecords([$this->link]);

        $this->link->forceFill(['cj_status' => CjProductStatus::Unavailable])->save();

        Livewire::test(ListProductLinks::class)
            ->filterTable('unavailable')
            ->assertCanSeeTableRecords([$this->link]);
    }

    public function test_hides_links_whose_lunar_product_is_soft_deleted(): void
    {
        $this->link->product->delete();

        Livewire::test(ListProductLinks::class)->assertCanNotSeeTableRecords([$this->link]);
    }

    public function test_sync_actions_queue_jobs(): void
    {
        Queue::fake();

        Livewire::test(ListProductLinks::class)->callTableAction('sync', $this->link);

        // SyncProductJob is intentionally ShouldBeUnique; Queue::fake() still runs the
        // PendingDispatch unique-lock check (it only fakes the actual push), so the lock
        // acquired above is never released by a real worker. Release it here to simulate
        // the first job having already been processed, so the bulk action below is
        // dispatched as a fresh request rather than deduped against the row action above.
        app(UniqueLock::class)->release(new SyncProductJob($this->link));

        Livewire::test(ListProductLinks::class)->callTableBulkAction('sync', [$this->link]);

        Queue::assertPushed(SyncProductJob::class, 2);
    }

    public function test_imports_new_variants(): void
    {
        Queue::fake();
        $this->link->forceFill(['new_cj_variant_ids' => ['v-3']])->save();

        Livewire::test(ListProductLinks::class)
            ->callTableAction('import_new_variants', $this->link)
            ->assertNotified('Import of new variants requested.');

        Queue::assertPushed(ImportNewVariantsJob::class, fn (ImportNewVariantsJob $job) => $job->link->is($this->link));
        Queue::assertPushedOn('cjdropshipping', ImportNewVariantsJob::class);
        $this->assertSame(['v-3'], $this->link->fresh()->new_cj_variant_ids);
    }
}
