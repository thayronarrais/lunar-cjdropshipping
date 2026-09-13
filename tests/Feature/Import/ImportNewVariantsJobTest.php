<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Import;

use Lunar\Models\ProductVariant;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\LunarCjDropshipping\Actions\ImportNewVariants;
use Thayron\LunarCjDropshipping\Jobs\ImportNewVariantsJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\Support\ImportsFixtureProduct;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ImportNewVariantsJobTest extends TestCase
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
        $this->link->forceFill(['new_cj_variant_ids' => ['v-3']])->save();
    }

    public function test_imports_the_pending_new_variants(): void
    {
        $detail = FakeCj::data('product-detail');
        $detail['variants'][] = ['vid' => 'v-3', 'pid' => 'p-100', 'variantSku' => 'CJ-CASE-RED-S', 'variantKey' => 'Red-S', 'variantSellPrice' => '10.00'];
        $this->cj->success($detail)->fixture('stock-by-pid');

        $this->runJob();

        $this->assertSame(1, ProductVariant::query()->where('sku', 'CJ-CASE-RED-S')->count());
        $this->assertSame([], $this->link->fresh()->new_cj_variant_ids);
    }

    public function test_releases_on_quota_errors(): void
    {
        $this->cj->error(1600201, 'Daily quota exhausted');

        $job = (new ImportNewVariantsJob($this->link))->withFakeQueueInteractions();
        $job->handle(app(ImportNewVariants::class));

        $job->assertReleased();
        $this->assertSame(['v-3'], $this->link->fresh()->new_cj_variant_ids);
    }

    public function test_records_transient_errors_and_rethrows(): void
    {
        $this->cj->error(1600000, 'System busy');

        try {
            $this->runJob();
            $this->fail('Expected ServerException.');
        } catch (ServerException) {
            $this->assertStringContainsString('System busy', (string) $this->link->fresh()->sync_error);
        }
    }

    public function test_records_other_errors_without_rethrowing(): void
    {
        $this->cj->error(1602001, 'Product not found');

        $this->runJob();

        $this->assertStringContainsString('Product not found', (string) $this->link->fresh()->sync_error);
    }

    private function runJob(): void
    {
        (new ImportNewVariantsJob($this->link))->handle(app(ImportNewVariants::class));
    }
}
