<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Support\Facades\DB;
use Thayron\CjDropshipping\CjClient;
use Thayron\LunarCjDropshipping\Catalog\OptionResolver;
use Thayron\LunarCjDropshipping\Catalog\VariantWriter;
use Thayron\LunarCjDropshipping\Mapping\VariantOptionParser;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\Throttle;

final class ImportNewVariants
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
        private readonly VariantOptionParser $parser,
        private readonly OptionResolver $options,
        private readonly VariantWriter $variants,
    ) {
    }

    public function handle(ProductLink $link): int
    {
        $pending = $link->new_cj_variant_ids;
        $product = $link->product;

        if ($pending === [] || $product === null) {
            return 0;
        }

        $this->throttle->wait();
        $cjProduct = $this->cj->products()->find($link->cj_product_id);
        $this->throttle->wait();
        $inventory = $this->cj->products()->inventoryByProduct($link->cj_product_id);

        return DB::transaction(function () use ($link, $product, $pending, $cjProduct, $inventory): int {
            $raw = $cjProduct->raw();
            $parsed = $this->parser->parse($raw['productKeyEn'] ?? null, $cjProduct->variants);
            $optionModels = array_map(fn (string $name) => $this->options->option($name), $parsed['options']);
            $attached = $product->productOptions()->get()->modelKeys();

            foreach ($optionModels as $position => $option) {
                if (! in_array($option->id, $attached, true)) {
                    $product->productOptions()->attach($option->id, ['position' => $position + 1]);
                }
            }

            $linked = $link->variantLinks()->pluck('cj_variant_id')->all();
            $created = 0;

            foreach ($cjProduct->variants as $cjVariant) {
                if (! in_array($cjVariant->id, $pending, true) || in_array($cjVariant->id, $linked, true)) {
                    continue;
                }

                $this->variants->create($product, $link, $cjProduct, $cjVariant, $parsed['values'][$cjVariant->id] ?? [], $optionModels, $inventory);
                $created++;
            }

            $link->forceFill(['new_cj_variant_ids' => []])->save();

            return $created;
        });
    }
}
