<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Catalog;

use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Thayron\CjDropshipping\Data\Product as CjProduct;
use Thayron\CjDropshipping\Data\ProductInventory;
use Thayron\CjDropshipping\Data\Variant as CjVariant;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Mapping\MeasurementConverter;
use Thayron\LunarCjDropshipping\Mapping\StockResolver;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Pricing\CostParser;
use Thayron\LunarCjDropshipping\Pricing\PriceCalculator;

final class VariantWriter
{
    public function __construct(
        private readonly OptionResolver $options,
        private readonly PriceCalculator $prices,
        private readonly StockResolver $stock,
        private readonly MeasurementConverter $measurements,
    ) {
    }

    /**
     * @param  list<string>  $values  option values in the same order as $options
     * @param  list<ProductOption>  $options
     */
    public function create(Product $product, ProductLink $link, CjProduct $cjProduct, CjVariant $cjVariant, array $values, array $options, ProductInventory $inventory): VariantLink
    {
        $taxClass = TaxClass::getDefault() ?? throw new ImportException('Lunar has no default tax class.');
        $stock = $this->stock->forVariant($inventory, $cjVariant->id, $link->country_code);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'tax_class_id' => $taxClass->id,
            'sku' => $cjVariant->sku,
            'unit_quantity' => 1,
            'purchasable' => 'in_stock',
            'shippable' => true,
            'backorder' => 0,
            'stock' => $stock,
            'weight_value' => $this->measurements->gramsToKilograms($cjVariant->weight),
            'weight_unit' => 'kg',
            'length_value' => $this->measurements->millimetersToCentimeters($cjVariant->length),
            'length_unit' => 'cm',
            'width_value' => $this->measurements->millimetersToCentimeters($cjVariant->width),
            'width_unit' => 'cm',
            'height_value' => $this->measurements->millimetersToCentimeters($cjVariant->height),
            'height_unit' => 'cm',
        ]);

        foreach ($values as $index => $value) {
            if (isset($options[$index])) {
                $variant->values()->attach($this->options->value($options[$index], $value)->id);
            }
        }

        $cost = $this->costFor($cjProduct, $cjVariant) ?? throw new ImportException("CJ variant {$cjVariant->id} has no price.");
        $this->writePrices($variant, $cost, $link);

        return VariantLink::create([
            'cj_variant_id' => $cjVariant->id,
            'cj_product_link_id' => $link->id,
            'lunar_variant_id' => $variant->id,
            'cj_sku' => $cjVariant->sku,
            'cost_usd' => $cost,
            'stock' => $stock,
            'last_synced_at' => now(),
        ]);
    }

    public function writePrices(ProductVariant $variant, string $costUsd, ProductLink $link): void
    {
        $amounts = $this->prices->pricesFor($costUsd, (string) $link->markup_percent, $link->rounding);

        foreach ($amounts as $currencyId => $amount) {
            Price::query()->updateOrCreate([
                'priceable_type' => $variant->getMorphClass(),
                'priceable_id' => $variant->id,
                'currency_id' => $currencyId,
                'customer_group_id' => null,
                'min_quantity' => 1,
            ], ['price' => $amount]);
        }
    }

    public function costFor(CjProduct $cjProduct, CjVariant $cjVariant): ?string
    {
        return CostParser::lowest($cjVariant->sellPrice) ?? CostParser::lowest($cjProduct->sellPrice);
    }
}
