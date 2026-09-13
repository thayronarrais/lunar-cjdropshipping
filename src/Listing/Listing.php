<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Listing;

use Thayron\LunarCjDropshipping\Enums\PriceRounding;

/**
 * A product confirmed by hand on the listing page, stored as JSON on the import list item.
 */
final readonly class Listing
{
    /**
     * @param  list<ListingVariant>  $variants
     */
    public function __construct(
        public string $name,
        public string $shipFromCountry,
        public string $shipToCountry,
        public string $currencyCode,
        public string $shippingMethod,
        public string $markupPercent,
        public PriceRounding $rounding,
        public int $productTypeId,
        public ?int $brandId,
        public ?int $collectionId,
        public array $variants,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $variants = is_array($data['variants'] ?? null) ? $data['variants'] : [];

        return new self(
            (string) ($data['name'] ?? ''),
            strtoupper((string) ($data['ship_from_country'] ?? '')),
            strtoupper((string) ($data['ship_to_country'] ?? '')),
            strtoupper((string) ($data['currency_code'] ?? '')),
            (string) ($data['shipping_method'] ?? ''),
            is_numeric($data['markup_percent'] ?? null) ? (string) $data['markup_percent'] : '0',
            PriceRounding::tryFrom((string) ($data['rounding'] ?? '')) ?? PriceRounding::None,
            (int) ($data['product_type_id'] ?? 0),
            filled($data['brand_id'] ?? null) ? (int) $data['brand_id'] : null,
            filled($data['collection_id'] ?? null) ? (int) $data['collection_id'] : null,
            array_values(array_map(ListingVariant::fromArray(...), array_filter($variants, is_array(...)))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'ship_from_country' => $this->shipFromCountry,
            'ship_to_country' => $this->shipToCountry,
            'currency_code' => $this->currencyCode,
            'shipping_method' => $this->shippingMethod,
            'markup_percent' => $this->markupPercent,
            'rounding' => $this->rounding->value,
            'product_type_id' => $this->productTypeId,
            'brand_id' => $this->brandId,
            'collection_id' => $this->collectionId,
            'variants' => array_map(fn (ListingVariant $variant): array => $variant->toArray(), $this->variants),
        ];
    }

    /**
     * @return list<ListingVariant>
     */
    public function selectedVariants(): array
    {
        return array_values(array_filter($this->variants, fn (ListingVariant $variant): bool => $variant->selected));
    }

    /**
     * @return list<string>
     */
    public function skippedVariantIds(): array
    {
        return array_values(array_map(
            fn (ListingVariant $variant): string => $variant->vid,
            array_filter($this->variants, fn (ListingVariant $variant): bool => ! $variant->selected),
        ));
    }
}
