<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Listing;

final readonly class ListingVariant
{
    /**
     * @param  string|null  $costUsd  CJ variant cost, USD.
     * @param  string|null  $shippingCostUsd  Shipping of the chosen method for this variant, USD.
     * @param  string|null  $price  Confirmed sale price in the listing currency (major units).
     */
    public function __construct(
        public string $vid,
        public bool $selected,
        public ?string $costUsd,
        public ?string $shippingCostUsd,
        public ?string $price,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['vid'] ?? ''),
            (bool) ($data['selected'] ?? false),
            self::decimal($data['cost_usd'] ?? null),
            self::decimal($data['shipping_cost_usd'] ?? null),
            self::decimal($data['price'] ?? null),
        );
    }

    /**
     * @return array{vid: string, selected: bool, cost_usd: string|null, shipping_cost_usd: string|null, price: string|null}
     */
    public function toArray(): array
    {
        return [
            'vid' => $this->vid,
            'selected' => $this->selected,
            'cost_usd' => $this->costUsd,
            'shipping_cost_usd' => $this->shippingCostUsd,
            'price' => $this->price,
        ];
    }

    private static function decimal(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && (string) $value !== '' ? (string) $value : null;
    }
}
