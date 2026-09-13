<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Import;

use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\LunarCjDropshipping\Actions\ImportProduct;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Jobs\ImportProductImagesJob;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ImportProductJobTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cjdropshipping.max_retries' => 0]);
        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);

        $rule = ImportRule::create(['name' => 'Rule', 'keyword' => 'case', 'markup_percent' => '100', 'rounding' => PriceRounding::Ends90, 'product_type_id' => $this->productType->id]);
        $this->candidate = Candidate::create(['import_rule_id' => $rule->id, 'cj_product_id' => 'p-100', 'name' => 'Case', 'status' => CandidateStatus::Approved, 'payload' => [], 'discovered_at' => now()]);
    }

    public function test_imports_then_queues_images_and_subscribes_the_product(): void
    {
        Bus::fake([ImportProductImagesJob::class]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid')
            ->success(['successProductIds' => ['p-100'], 'failProductIds' => [], 'subscribeAll' => false])
            ->success(true);

        $this->runJob();

        $this->assertSame(CandidateStatus::Imported, $this->candidate->fresh()->status);
        Bus::assertDispatched(ImportProductImagesJob::class, fn (ImportProductImagesJob $job) => count($job->urls) === 2);
        $this->assertSame('webhook/product/subscribe', $this->cj->paths()[2]);
        $this->assertSame(['productIds' => ['p-100']], $this->cj->jsonAt(2));
        $this->assertSame('product/addToMyProduct', $this->cj->paths()[3]);
        $this->assertSame(['productId' => 'p-100'], $this->cj->jsonAt(3));
    }

    public function test_marks_unavailable_products_as_failed_without_retrying(): void
    {
        $this->cj->error(1602001, 'Product not found');

        $this->runJob();

        $candidate = $this->candidate->fresh();
        $this->assertSame(CandidateStatus::Failed, $candidate->status);
        $this->assertSame('Product is no longer available on CJdropshipping.', $candidate->error);
    }

    public function test_marks_transient_errors_as_failed_and_rethrows(): void
    {
        $this->cj->error(1600000, 'System busy');

        try {
            $this->runJob();
            $this->fail('Expected ServerException.');
        } catch (ServerException) {
            $candidate = $this->candidate->fresh();
            $this->assertSame(CandidateStatus::Failed, $candidate->status);
            $this->assertStringContainsString('System busy', (string) $candidate->error);
        }
    }

    public function test_releases_until_tomorrow_on_quota_errors(): void
    {
        $this->cj->error(1600201, 'Daily quota exhausted');

        $job = (new ImportProductJob($this->candidate))->withFakeQueueInteractions();
        $job->handle(app(ImportProduct::class), app(CjClient::class), app(Throttle::class));

        $job->assertReleased();
        $this->assertSame(CandidateStatus::Approved, $this->candidate->fresh()->status);
    }

    public function test_a_failed_webhook_subscription_does_not_fail_the_import(): void
    {
        Bus::fake([ImportProductImagesJob::class]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid')->error(1600000, 'System busy')->success(true);

        $this->runJob();

        $this->assertSame(CandidateStatus::Imported, $this->candidate->fresh()->status);
    }

    public function test_a_failed_add_to_my_products_does_not_fail_the_import(): void
    {
        Bus::fake([ImportProductImagesJob::class]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid')
            ->success(['successProductIds' => ['p-100'], 'failProductIds' => [], 'subscribeAll' => false])
            ->error(1600000, 'The product has been added to My Products.');

        $this->runJob();

        $this->assertSame(CandidateStatus::Imported, $this->candidate->fresh()->status);
    }

    public function test_failed_hook_marks_the_candidate_failed(): void
    {
        $this->candidate->forceFill(['status' => CandidateStatus::Importing])->save();

        (new ImportProductJob($this->candidate))->failed(new RuntimeException('boom'));

        $candidate = $this->candidate->fresh();
        $this->assertSame(CandidateStatus::Failed, $candidate->status);
        $this->assertSame('boom', $candidate->error);
    }

    private function runJob(): void
    {
        (new ImportProductJob($this->candidate))->handle(app(ImportProduct::class), app(CjClient::class), app(Throttle::class));
    }
}
