<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Support\Facades\DB;
use Lunar\Models\Currency;
use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Data\Variant as CjVariant;
use Thayron\CjDropshipping\Exceptions\NotFoundException;
use Thayron\LunarCjDropshipping\Catalog\VariantWriter;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\UnavailableAction;
use Thayron\LunarCjDropshipping\Mapping\StockResolver;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Pricing\ListingPriceCalculator;
use Thayron\LunarCjDropshipping\Support\Throttle;

/**
 * Syncs stock, cost-based prices (unless locked by a confirmed listing) and availability. Never touches names, descriptions, images or options.
 */
final class SyncProduct
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
        private readonly StockResolver $stock,
        private readonly VariantWriter $variants,
        private readonly ListingPriceCalculator $listingPrices,
    ) {}

    public function handle(ProductLink $link): void
    {
        try {
            $this->throttle->wait();
            $cjProduct = $this->cj->products()->find($link->cj_product_id);
        } catch (NotFoundException) {
            $this->recordNotFound($link);

            return;
        }

        $this->throttle->wait();
        $inventory = $this->cj->products()->inventoryByProduct($link->cj_product_id);

        /** @var array<string, CjVariant> $cjVariants */
        $cjVariants = collect($cjProduct->variants)->keyBy(fn (CjVariant $variant) => $variant->id)->all();

        DB::transaction(function () use ($link, $cjProduct, $cjVariants, $inventory): void {
            /** @var list<int> $enabledCurrencyIds */
            $enabledCurrencyIds = Currency::query()->where('enabled', true)->pluck('id')->all();

            $currency = $link->price_locked ? Currency::query()->where('code', (string) $link->currency_code)->first() : null;
            $minimumMargin = (string) config('lunar-cjdropshipping.pricing.min_margin_percent', 20);
            $marginAtRisk = false;

            foreach ($link->variantLinks()->with('variant')->get() as $variantLink) {
                $variant = $variantLink->variant;

                if ($variant === null) {
                    continue;
                }

                $cjVariant = $cjVariants[$variantLink->cj_variant_id] ?? null;

                if ($cjVariant === null) {
                    $variant->update(['stock' => 0]);
                    $variantLink->update(['stock' => 0, 'last_synced_at' => now()]);

                    continue;
                }

                $stock = $this->stock->forVariant($inventory, $cjVariant->id, $link->country_code);
                $cost = $this->variants->costFor($cjProduct, $cjVariant);
                $variant->update(['stock' => $stock]);

                $costChanged = $variantLink->cost_usd === null || ($cost !== null && bccomp($cost, (string) $variantLink->cost_usd, 2) !== 0);

                if ($link->price_locked) {
                    $marginAtRisk = $marginAtRisk || $this->isMarginAtRisk($variantLink, $cost ?? $variantLink->cost_usd, $currency, $minimumMargin);
                } elseif ($cost !== null && ($costChanged || $this->isMissingPrices($variant, $enabledCurrencyIds))) {
                    $this->variants->writePrices($variant, $cost, $link);
                }

                $variantLink->update(['stock' => $stock, 'cost_usd' => $cost ?? $variantLink->cost_usd, 'last_synced_at' => now()]);
            }

            $known = $link->variantLinks()->pluck('cj_variant_id')->all();

            $link->forceFill([
                'not_found_count' => 0,
                'cj_status' => CjProductStatus::Active,
                'new_cj_variant_ids' => array_values(array_diff(array_map('strval', array_keys($cjVariants)), $known, $link->skipped_cj_variant_ids ?? [])),
                'last_synced_at' => now(),
                'sync_error' => null,
                'margin_at_risk' => $marginAtRisk,
            ])->save();
        });
    }

    /**
     * Whether the variant lacks a base price (no customer group, min quantity 1) in any enabled currency.
     *
     * @param  list<int>  $enabledCurrencyIds
     */
    private function isMissingPrices(ProductVariant $variant, array $enabledCurrencyIds): bool
    {
        $priced = Price::query()
            ->where('priceable_type', $variant->getMorphClass())
            ->where('priceable_id', $variant->id)
            ->whereNull('customer_group_id')
            ->where('min_quantity', 1)
            ->whereIn('currency_id', $enabledCurrencyIds)
            ->distinct()
            ->count('currency_id');

        return $priced < count($enabledCurrencyIds);
    }

    private function isMarginAtRisk(VariantLink $variantLink, ?string $costUsd, ?Currency $currency, string $minimumMargin): bool
    {
        if ($currency === null || $costUsd === null || $variantLink->price === null || $variantLink->shipping_cost_usd === null) {
            return true;
        }

        $margin = $this->listingPrices->margin((string) $variantLink->price, $costUsd, (string) $variantLink->shipping_cost_usd, $currency);

        return $margin === null || bccomp($margin, $minimumMargin, 2) < 0;
    }

    private function recordNotFound(ProductLink $link): void
    {
        $count = $link->not_found_count + 1;
        $threshold = (int) config('lunar-cjdropshipping.sync.not_found_threshold', 2);

        if ($count < $threshold) {
            $link->forceFill([
                'not_found_count' => $count,
                'sync_error' => sprintf('CJ product not found (%d/%d).', $count, $threshold),
            ])->save();

            return;
        }

        $link->forceFill(['not_found_count' => $count])->save();

        DB::transaction(function () use ($link): void {
            foreach ($link->variantLinks()->with('variant')->get() as $variantLink) {
                $variantLink->variant?->update(['stock' => 0]);
                $variantLink->update(['stock' => 0, 'last_synced_at' => now()]);
            }

            if (config('lunar-cjdropshipping.sync.unavailable_action') === UnavailableAction::Draft->value) {
                $link->product?->update(['status' => 'draft']);
            }

            $link->forceFill(['cj_status' => CjProductStatus::Unavailable, 'last_synced_at' => now(), 'sync_error' => null])->save();
        });
    }
}
