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
     * Profit per unit in the listing currency after VAT (when prices include it), card fee and converted cost, or null when the price is not positive.
     */
    public function profit(string $price, string $costUsd, string $shippingUsd, Currency $currency, ?Vat $vat = null): ?string
    {
        $profit = $this->exactProfit($price, $costUsd, $shippingUsd, $currency, $vat);

        return $profit === null ? null : self::roundHalfUp($profit, (int) $currency->decimal_places);
    }

    /**
     * Profit as a percentage of the price, or null when the price is not positive.
     */
    public function margin(string $price, string $costUsd, string $shippingUsd, Currency $currency, ?Vat $vat = null): ?string
    {
        $profit = $this->exactProfit($price, $costUsd, $shippingUsd, $currency, $vat);

        if ($profit === null) {
            return null;
        }

        return self::roundHalfUp(bcmul(bcdiv($profit, $price, self::SCALE), '100', self::SCALE), 2);
    }

    public function inCurrency(string $usd, Currency $currency): string
    {
        return self::roundHalfUp(bcmul($usd, $this->prices->usdToCurrencyRate($currency), self::SCALE), (int) $currency->decimal_places);
    }

    private function exactProfit(string $price, string $costUsd, string $shippingUsd, Currency $currency, ?Vat $vat): ?string
    {
        if (bccomp($price, '0', self::SCALE) <= 0) {
            return null;
        }

        $vat ??= Vat::none();
        $vatAmount = $vat->inclusive
            ? bcdiv(bcmul($price, $vat->percent, self::SCALE), bcadd('100', $vat->percent, self::SCALE), self::SCALE)
            : '0';

        $fee = bcadd(bcdiv(bcmul($price, self::configNumber('card_fee_percent', '1.5'), self::SCALE), '100', self::SCALE), self::configNumber('card_fee_fixed', '0.20'), self::SCALE);
        $cost = bcmul(bcadd($costUsd, $shippingUsd, self::SCALE), $this->prices->usdToCurrencyRate($currency), self::SCALE);

        return bcsub(bcsub(bcsub($price, $vatAmount, self::SCALE), $fee, self::SCALE), $cost, self::SCALE);
    }

    private static function configNumber(string $key, string $default): string
    {
        $value = config("lunar-cjdropshipping.pricing.{$key}", $default);

        return is_numeric($value) ? (string) $value : $default;
    }

    public static function roundHalfUp(string $value, int $decimals): string
    {
        $offset = '0.'.str_repeat('0', $decimals).'5';

        return str_starts_with($value, '-')
            ? bcsub($value, $offset, $decimals)
            : bcadd($value, $offset, $decimals);
    }
}
