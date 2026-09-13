<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Criteria\ProductSearch;
use Thayron\CjDropshipping\Data\ProductSummary;
use Thayron\LunarCjDropshipping\Enums\CandidateSource;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Pricing\CostParser;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Throwable;

final class DiscoverCandidates
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
    ) {}

    /**
     * @return array{found: int, created: int, updated: int, skipped_ignored: int, already_imported: int}
     */
    public function handle(ImportRule $rule): array
    {
        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped_ignored' => 0, 'already_imported' => 0];

        try {
            foreach ($this->searches($rule) as $search) {
                for ($page = 1; $page <= $rule->max_pages; $page++) {
                    $this->throttle->wait();
                    $result = $this->cj->products()->search($search->page($page));

                    foreach ($result->items as $summary) {
                        if (! $this->matches($rule, $summary)) {
                            continue;
                        }

                        $stats['found']++;
                        $stats[$this->upsert($rule, $summary)]++;
                    }

                    if (! $result->hasMorePages()) {
                        break;
                    }
                }
            }
        } catch (Throwable $exception) {
            $rule->forceFill(['last_run_at' => now(), 'last_run_stats' => [...$stats, 'error' => $exception->getMessage()]])->save();

            throw $exception;
        }

        $rule->forceFill(['last_run_at' => now(), 'last_run_stats' => $stats])->save();

        return $stats;
    }

    /**
     * @return list<ProductSearch>
     */
    private function searches(ImportRule $rule): array
    {
        $base = ProductSearch::make()->perPage(100);

        if (filled($rule->keyword)) {
            $base = $base->keyword((string) $rule->keyword);
        }

        if (filled($rule->country_code)) {
            $base = $base->country((string) $rule->country_code);
        }

        $categories = array_values(array_filter($rule->category_ids ?? [], 'filled'));

        if ($categories === []) {
            return [$base];
        }

        return array_map(fn (string $categoryId): ProductSearch => $base->category($categoryId), $categories);
    }

    private function matches(ImportRule $rule, ProductSummary $summary): bool
    {
        if (($summary->warehouseInventory ?? 0) < $rule->min_stock) {
            return false;
        }

        $cost = CostParser::lowest($summary->sellPrice);

        if ($rule->min_cost !== null && ($cost === null || bccomp($cost, (string) $rule->min_cost, 2) < 0)) {
            return false;
        }

        if ($rule->max_cost !== null && ($cost === null || bccomp($cost, (string) $rule->max_cost, 2) > 0)) {
            return false;
        }

        return true;
    }

    /**
     * @return 'created'|'updated'|'skipped_ignored'|'already_imported'
     */
    private function upsert(ImportRule $rule, ProductSummary $summary): string
    {
        $candidate = Candidate::query()->firstOrNew(['cj_product_id' => $summary->id]);

        if ($candidate->exists && $candidate->status === CandidateStatus::Ignored) {
            return 'skipped_ignored';
        }

        $link = ProductLink::query()->whereHas('product')->where('cj_product_id', $summary->id)->first();
        $isNew = ! $candidate->exists;

        $candidate->fillFromSummary($summary);

        if ($link !== null) {
            $candidate->status = CandidateStatus::Imported;
            $candidate->lunar_product_id = $link->lunar_product_id;
        } elseif ($isNew || $candidate->status === CandidateStatus::Imported) {
            $candidate->status = CandidateStatus::Pending;
            $candidate->lunar_product_id = null;
        }

        if ($isNew) {
            $candidate->import_rule_id = $rule->id;
            $candidate->source = CandidateSource::Rule;
            $candidate->discovered_at = now();
        }

        $candidate->save();

        return match (true) {
            $link !== null => 'already_imported',
            $isNew => 'created',
            default => 'updated',
        };
    }
}
