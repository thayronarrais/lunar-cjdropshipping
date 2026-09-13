<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource\Pages\ListCandidates;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Tests\FilamentTestCase;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;

final class CandidateResourceTest extends FilamentTestCase
{
    use CreatesLunarBaseline;

    private ImportRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createLunarBaseline();
        $this->actingAsStaff();
        $this->rule = ImportRule::create(['name' => 'Rule', 'keyword' => 'case', 'markup_percent' => '100', 'rounding' => PriceRounding::Ends90, 'product_type_id' => $this->productType->id]);
    }

    public function test_shows_pending_candidates_by_default_with_calculated_prices(): void
    {
        $pending = $this->candidate('p-1', CandidateStatus::Pending);
        $ignored = $this->candidate('p-2', CandidateStatus::Ignored);

        Livewire::test(ListCandidates::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$ignored])
            ->assertSee('18.90 EUR');
    }

    public function test_bulk_import_approves_and_queues_candidates(): void
    {
        $pending = $this->candidate('p-1', CandidateStatus::Pending);
        $failed = $this->candidate('p-2', CandidateStatus::Failed);
        $imported = $this->candidate('p-3', CandidateStatus::Imported);

        Livewire::test(ListCandidates::class)
            ->filterTable('status', null)
            ->callTableBulkAction('import', [$pending, $failed, $imported]);

        $this->assertSame(CandidateStatus::Approved, $pending->fresh()->status);
        $this->assertSame(CandidateStatus::Approved, $failed->fresh()->status);
        $this->assertSame(CandidateStatus::Imported, $imported->fresh()->status);
        Queue::assertPushed(ImportProductJob::class, 2);
    }

    public function test_bulk_ignore_and_reset(): void
    {
        $candidate = $this->candidate('p-1', CandidateStatus::Pending);

        Livewire::test(ListCandidates::class)->callTableBulkAction('ignore', [$candidate]);
        $this->assertSame(CandidateStatus::Ignored, $candidate->fresh()->status);

        Livewire::test(ListCandidates::class)->filterTable('status', 'ignored')->callTableBulkAction('reset', [$candidate]);
        $this->assertSame(CandidateStatus::Pending, $candidate->fresh()->status);
    }

    public function test_retry_action_queues_failed_candidates(): void
    {
        $candidate = $this->candidate('p-1', CandidateStatus::Failed);

        Livewire::test(ListCandidates::class)
            ->filterTable('status', 'failed')
            ->callTableAction('retry', $candidate);

        Queue::assertPushed(ImportProductJob::class, fn (ImportProductJob $job) => $job->candidate->is($candidate));
    }

    private function candidate(string $cjProductId, CandidateStatus $status): Candidate
    {
        return Candidate::create([
            'import_rule_id' => $this->rule->id,
            'cj_product_id' => $cjProductId,
            'cj_sku' => 'SKU-'.$cjProductId,
            'name' => 'Product '.$cjProductId,
            'cost_usd' => '10.00',
            'warehouse_stock' => 10,
            'status' => $status,
            'payload' => [],
            'discovered_at' => now(),
        ]);
    }
}
