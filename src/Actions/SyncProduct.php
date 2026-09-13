<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Support\Facades\DB;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Data\Variant as CjVariant;
use Thayron\CjDropshipping\Exceptions\NotFoundException;
use Thayron\LunarCjDropshipping\Catalog\VariantWriter;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\UnavailableAction;
use Thayron\LunarCjDropshipping\Mapping\StockResolver;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\Throttle;

/**
 * Syncs stock, cost-based prices and availability. Never touches names, descriptions, images or options.
 */
final class SyncProduct
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
        private readonly StockResolver $stock,
        private readonly VariantWriter $variants,
    ) {
    }

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

                if ($cost !== null && ($variantLink->cost_usd === null || bccomp($cost, (string) $variantLink->cost_usd, 2) !== 0)) {
                    $this->variants->writePrices($variant, $cost, $link);
                }

                $variantLink->update(['stock' => $stock, 'cost_usd' => $cost ?? $variantLink->cost_usd, 'last_synced_at' => now()]);
            }

            $known = $link->variantLinks()->pluck('cj_variant_id')->all();

            $link->forceFill([
                'not_found_count' => 0,
                'cj_status' => CjProductStatus::Active,
                'new_cj_variant_ids' => array_values(array_diff(array_keys($cjVariants), $known)),
                'last_synced_at' => now(),
                'sync_error' => null,
            ])->save();
        });
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
