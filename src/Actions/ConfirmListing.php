<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Lunar\Models\Currency;
use Lunar\Models\ProductType;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Exceptions\ListingException;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Listing\Listing;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Pricing\ListingPriceCalculator;

final class ConfirmListing
{
    public function __construct(private readonly ListingPriceCalculator $prices) {}

    /**
     * @throws ListingException
     */
    public function handle(Candidate $candidate, Listing $listing, bool $acceptNegativeMargin): void
    {
        if (! in_array($candidate->status, [CandidateStatus::Pending, CandidateStatus::Failed], true)) {
            throw ListingException::because('not_confirmable');
        }

        $name = trim($listing->name);

        if ($name === '') {
            throw ListingException::because('name_required');
        }

        if (mb_strlen($name) > 255) {
            throw ListingException::because('name_too_long');
        }

        if (preg_match('/^[A-Z]{2}$/', $listing->shipFromCountry) !== 1 || preg_match('/^[A-Z]{2}$/', $listing->shipToCountry) !== 1) {
            throw ListingException::because('countries_required');
        }

        $currency = Currency::query()->where('code', $listing->currencyCode)->where('enabled', true)->first()
            ?? throw ListingException::because('currency_invalid');

        if (trim($listing->shippingMethod) === '') {
            throw ListingException::because('method_required');
        }

        if (! ProductType::query()->whereKey($listing->productTypeId)->exists()) {
            throw ListingException::because('product_type_required');
        }

        $selected = $listing->selectedVariants();

        if ($selected === []) {
            throw ListingException::because('no_variants');
        }

        $negative = false;

        foreach ($selected as $variant) {
            if ($variant->price === null || ! is_numeric($variant->price) || bccomp($variant->price, '0', 2) <= 0) {
                throw ListingException::because('price_required');
            }

            if ($variant->shippingCostUsd === null) {
                throw ListingException::because('shipping_missing', ['sku' => $variant->vid]);
            }

            if ($variant->costUsd === null) {
                throw ListingException::because('cost_missing', ['sku' => $variant->vid]);
            }

            $margin = $this->prices->margin($variant->price, $variant->costUsd, $variant->shippingCostUsd, $currency);
            $negative = $negative || ($margin !== null && bccomp($margin, '0', 2) < 0);
        }

        if ($negative && ! $acceptNegativeMargin) {
            throw ListingException::because('negative_margin');
        }

        $candidate->forceFill([
            'name' => $name,
            'listing' => $listing->toArray(),
            'listed_at' => now(),
            'status' => CandidateStatus::Approved,
            'error' => null,
        ])->save();

        ImportProductJob::dispatch($candidate);
    }
}
