<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

use Lunar\Models\Currency;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\PricingException;

/**
 * Sale price = CJ cost (USD) x (1 + markup) converted to each enabled Lunar currency.
 * Lunar exchange rates are relative to the default currency.
 */
final class PriceCalculator
{
    private const SCALE = 12;

    /**
     * @return array<int, int> currency id => price in minor units
     */
    public function pricesFor(string $costUsd, string $markupPercent, PriceRounding $rounding): array
    {
        $usdRate = $this->usdRate();
        $prices = [];

        foreach (Currency::query()->where('enabled', true)->orderBy('id')->get() as $currency) {
            $prices[$currency->id] = $this->priceFor($costUsd, $markupPercent, $rounding, $currency, $usdRate);
        }

        return $prices;
    }

    public function priceFor(string $costUsd, string $markupPercent, PriceRounding $rounding, Currency $currency, ?string $usdRate = null): int
    {
        $usdRate ??= $this->usdRate();
        $currencyRate = $currency->default ? '1' : self::decimal($currency->exchange_rate);
        $multiplier = bcadd('1', bcdiv(self::decimal($markupPercent), '100', self::SCALE), self::SCALE);

        $major = bcmul(bcmul(bcmul(self::decimal($costUsd), $multiplier, self::SCALE), $usdRate, self::SCALE), $currencyRate, self::SCALE);

        return $this->round($major, (int) $currency->decimal_places, $rounding);
    }

    /**
     * Value of 1 USD in the store default currency.
     */
    public function usdRate(): string
    {
        $usd = Currency::query()->where('code', 'USD')->first();

        if ($usd !== null) {
            if ($usd->default) {
                return '1';
            }

            if (bccomp(self::decimal($usd->exchange_rate), '0', self::SCALE) > 0) {
                return bcdiv('1', self::decimal($usd->exchange_rate), self::SCALE);
            }
        }

        $configured = config('lunar-cjdropshipping.pricing.usd_to_default_rate');

        if (is_numeric($configured) && bccomp(self::decimal($configured), '0', self::SCALE) > 0) {
            return self::decimal($configured);
        }

        throw new PricingException('Cannot convert CJdropshipping USD costs: create a USD currency in Lunar or set lunar-cjdropshipping.pricing.usd_to_default_rate.');
    }

    private function round(string $major, int $decimalPlaces, PriceRounding $rounding): int
    {
        if ($decimalPlaces === 0 && in_array($rounding, [PriceRounding::Ends90, PriceRounding::Ends99], true)) {
            $rounding = PriceRounding::Whole;
        }

        $factor = bcpow('10', (string) $decimalPlaces);

        $roundedMajor = match ($rounding) {
            PriceRounding::None => $major,
            PriceRounding::Whole => self::ceil($major),
            PriceRounding::Ends90 => self::endingIn($major, '0.10'),
            PriceRounding::Ends99 => self::endingIn($major, '0.01'),
        };

        return (int) bcadd(bcmul($roundedMajor, $factor, self::SCALE), '0.5', 0);
    }

    private static function endingIn(string $major, string $offset): string
    {
        $candidate = bcsub(self::ceil($major), $offset, self::SCALE);

        if (bccomp($candidate, $major, self::SCALE) < 0) {
            $candidate = bcadd($candidate, '1', self::SCALE);
        }

        return $candidate;
    }

    private static function ceil(string $value): string
    {
        $integer = bcadd($value, '0', 0);

        return bccomp($value, $integer, self::SCALE) > 0 ? bcadd($integer, '1', 0) : $integer;
    }

    private static function decimal(mixed $value): string
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        return number_format((float) $value, self::SCALE, '.', '');
    }
}
