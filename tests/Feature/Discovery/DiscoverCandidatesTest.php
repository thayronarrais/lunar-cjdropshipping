<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Discovery;

use Illuminate\Support\Facades\Queue;
use Lunar\Models\Product;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\LunarCjDropshipping\Actions\DiscoverCandidates;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Jobs\DiscoverCandidatesJob;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class DiscoverCandidatesTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
    }

    public function test_creates_pending_candidates_matching_the_rule_filters(): void
    {
        $rule = $this->rule(['category_ids' => ['cat-1'], 'keyword' => 'case', 'country_code' => 'us', 'min_stock' => 10]);
        $this->cj->fixture('list-v2-page');

        $stats = app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame(['found' => 1, 'created' => 1, 'updated' => 0, 'skipped_ignored' => 0, 'already_imported' => 0], $stats);
        $this->assertSame(['product/listV2'], $this->cj->paths());
        $this->assertSame(['keyWord' => 'case', 'categoryId' => 'cat-1', 'countryCode' => 'US', 'page' => '1', 'size' => '100'], $this->cj->queryAt(0));

        $candidate = Candidate::query()->sole();
        $this->assertSame('p-100', $candidate->cj_product_id);
        $this->assertSame('Magnetic Phone Case', $candidate->name);
        $this->assertSame('11.85', $candidate->cost_usd);
        $this->assertSame(500, $candidate->warehouse_stock);
        $this->assertSame(CandidateStatus::Pending, $candidate->status);
        $this->assertSame('CJ-CASE', $candidate->payload['sku']);
        $this->assertSame($stats, $rule->fresh()->last_run_stats);
        $this->assertNotNull($rule->fresh()->last_run_at);
    }

    public function test_filters_by_cost_range_using_the_lowest_price(): void
    {
        $rule = $this->rule(['keyword' => 'x', 'min_cost' => '1.00', 'max_cost' => '2.00']);
        $this->cj->fixture('list-v2-page');

        app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame(['p-200'], Candidate::query()->pluck('cj_product_id')->all());
        $this->assertSame('1.20', Candidate::query()->sole()->cost_usd);
    }

    public function test_runs_one_search_per_category(): void
    {
        $rule = $this->rule(['category_ids' => ['cat-1', 'cat-2']]);
        $this->cj->fixture('list-v2-page')->fixture('list-v2-page');

        $stats = app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame('cat-2', $this->cj->queryAt(1)['categoryId']);
        $this->assertSame(2, $stats['created']);
        $this->assertSame(2, $stats['updated']);
    }

    public function test_keeps_ignored_candidates_and_marks_linked_products_as_imported(): void
    {
        $rule = $this->rule(['keyword' => 'x']);
        Candidate::create([
            'import_rule_id' => $rule->id, 'cj_product_id' => 'p-100', 'name' => 'Old name',
            'status' => CandidateStatus::Ignored, 'payload' => [], 'discovered_at' => now()->subDay(),
        ]);
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        ProductLink::create(['cj_product_id' => 'p-200', 'lunar_product_id' => $product->id, 'markup_percent' => '0', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []]);
        $this->cj->fixture('list-v2-page');

        $stats = app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame(1, $stats['skipped_ignored']);
        $this->assertSame(1, $stats['already_imported']);
        $ignored = Candidate::query()->where('cj_product_id', 'p-100')->sole();
        $this->assertSame(CandidateStatus::Ignored, $ignored->status);
        $this->assertSame('Old name', $ignored->name);
        $imported = Candidate::query()->where('cj_product_id', 'p-200')->sole();
        $this->assertSame(CandidateStatus::Imported, $imported->status);
        $this->assertSame($product->id, $imported->lunar_product_id);
    }

    public function test_links_to_soft_deleted_products_do_not_count_as_imported(): void
    {
        $rule = $this->rule(['keyword' => 'x']);
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        ProductLink::create(['cj_product_id' => 'p-100', 'lunar_product_id' => $product->id, 'markup_percent' => '0', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []]);
        ProductLink::create(['cj_product_id' => 'p-200', 'lunar_product_id' => $product->id, 'markup_percent' => '0', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []]);
        Candidate::create([
            'import_rule_id' => $rule->id, 'cj_product_id' => 'p-200', 'name' => 'Cable Organizer', 'lunar_product_id' => $product->id,
            'status' => CandidateStatus::Imported, 'payload' => [], 'discovered_at' => now()->subDay(),
        ]);
        $product->delete();
        $this->cj->fixture('list-v2-page');

        $stats = app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame(['found' => 2, 'created' => 1, 'updated' => 1, 'skipped_ignored' => 0, 'already_imported' => 0], $stats);
        $this->assertSame(CandidateStatus::Pending, Candidate::query()->where('cj_product_id', 'p-100')->sole()->status);
        $existing = Candidate::query()->where('cj_product_id', 'p-200')->sole();
        $this->assertSame(CandidateStatus::Pending, $existing->status);
        $this->assertNull($existing->lunar_product_id);
    }

    public function test_updates_pending_candidates_on_rerun(): void
    {
        $rule = $this->rule(['keyword' => 'x']);
        $this->cj->fixture('list-v2-page')->fixtureWith('list-v2-page', [
            'content' => [['productList' => [['id' => 'p-100', 'nameEn' => 'Magnetic Phone Case', 'sellPrice' => '9.99', 'warehouseInventoryNum' => 42]]]],
        ]);

        app(DiscoverCandidates::class)->handle($rule);
        $stats = app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame(2, $stats['updated']);
        $candidate = Candidate::query()->where('cj_product_id', 'p-100')->sole();
        $this->assertSame('9.99', $candidate->cost_usd);
        $this->assertSame(42, $candidate->warehouse_stock);
    }

    public function test_respects_max_pages(): void
    {
        $rule = $this->rule(['keyword' => 'x', 'max_pages' => 1]);
        $this->cj->fixtureWith('list-v2-page', ['totalPages' => 9]);

        app(DiscoverCandidates::class)->handle($rule);

        $this->assertCount(1, $this->cj->requests());
    }

    public function test_records_the_error_and_rethrows(): void
    {
        config(['cjdropshipping.max_retries' => 0]);
        $this->app->forgetInstance(CjClient::class);
        $rule = $this->rule(['keyword' => 'x']);
        $this->cj->error(1600000, 'System busy')->error(1600000)->error(1600000);

        try {
            app(DiscoverCandidates::class)->handle($rule);
            $this->fail('Expected ServerException.');
        } catch (ServerException) {
            $this->assertStringContainsString('System busy', (string) $rule->fresh()->last_run_stats['error']);
        }
    }

    public function test_job_releases_until_tomorrow_when_quota_is_exhausted(): void
    {
        $rule = $this->rule(['keyword' => 'x']);
        $this->cj->error(1600201, 'Daily quota exhausted');

        $job = (new DiscoverCandidatesJob($rule))->withFakeQueueInteractions();
        $job->handle(app(DiscoverCandidates::class));

        $job->assertReleased();
    }

    public function test_command_dispatches_jobs_for_active_rules(): void
    {
        Queue::fake();
        $active = $this->rule(['keyword' => 'a']);
        $this->rule(['keyword' => 'b', 'is_active' => false]);

        $this->artisan('cj:discover')->expectsOutput('Dispatched 1 discovery job(s).')->assertSuccessful();

        Queue::assertPushed(DiscoverCandidatesJob::class, fn (DiscoverCandidatesJob $job) => $job->rule->is($active));
        Queue::assertPushedOn('cjdropshipping', DiscoverCandidatesJob::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rule(array $attributes): ImportRule
    {
        return ImportRule::create([
            'name' => 'Rule',
            'markup_percent' => '100',
            'rounding' => PriceRounding::Ends90,
            'product_type_id' => $this->productType->id,
            ...$attributes,
        ]);
    }
}
