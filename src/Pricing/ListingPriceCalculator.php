<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

use Lunar\Models\Currency;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;

/**
 * Prices confirmed by hand on the listing page: recommendation = (CJ cost + shipping) x (1 + markup), in one currency.
 */
final class ListingPriceCalculator
{
    private const SCALE = 12;

    public function __construct(private readonly PriceCalculator $prices) {}

    public function recommend(string $costUsd, string $shippingUsd, string $markupPercent, PriceRounding $rounding, Currency $currency): string
    {
        $minor = $this->prices->priceFor(bcadd($costUsd, $shippingUsd, self::SCALE), $markupPercent, $rounding, $currency);
        $decimals = (int) $currency->decimal_places;

        return bcdiv((string) $minor, bcpow('10', (string) $decimals), $decimals);
    }

    /**
     * Margin as a percentage of the price, or null when the price is not positive.
     */
    public function margin(string $price, string $costUsd, string $shippingUsd, Currency $currency): ?string
    {
        if (bccomp($price, '0', self::SCALE) <= 0) {
            return null;
        }

        $total = bcmul(bcadd($costUsd, $shippingUsd, self::SCALE), $this->prices->usdToCurrencyRate($currency), self::SCALE);
        $ratio = bcdiv(bcsub($price, $total, self::SCALE), $price, self::SCALE);

        return self::roundHalfUp(bcmul($ratio, '100', self::SCALE), 2);
    }

    public function inCurrency(string $usd, Currency $currency): string
    {
        return self::roundHalfUp(bcmul($usd, $this->prices->usdToCurrencyRate($currency), self::SCALE), (int) $currency->decimal_places);
    }

    public static function roundHalfUp(string $value, int $decimals): string
    {
        $offset = '0.'.str_repeat('0', $decimals).'5';

        return str_starts_with($value, '-')
            ? bcsub($value, $offset, $decimals)
            : bcadd($value, $offset, $decimals);
    }
}
