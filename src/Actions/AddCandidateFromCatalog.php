<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Thayron\CjDropshipping\Data\ProductSummary;
use Thayron\LunarCjDropshipping\Enums\CandidateSource;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ProductLink;

final class AddCandidateFromCatalog
{
    /**
     * Adds the product to the import list; null when it is already there.
     */
    public function handle(ProductSummary $summary): ?Candidate
    {
        if (Candidate::query()->where('cj_product_id', $summary->id)->exists()) {
            return null;
        }

        $candidate = new Candidate;
        $candidate->fillFromSummary($summary);
        $candidate->source = CandidateSource::Catalog;
        $candidate->status = CandidateStatus::Pending;
        $candidate->discovered_at = now();

        $link = ProductLink::query()->whereHas('product')->where('cj_product_id', $summary->id)->first();

        if ($link !== null) {
            $candidate->status = CandidateStatus::Imported;
            $candidate->lunar_product_id = $link->lunar_product_id;
        }

        try {
            $candidate->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $candidate;
    }
}
