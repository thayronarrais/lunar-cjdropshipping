<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Support;

use Lunar\Models\Currency;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\PricingException;
use Thayron\LunarCjDropshipping\Pricing\PriceCalculator;

final class PricePreview
{
    public static function for(mixed $markupPercent, mixed $rounding, string $costUsd = '10.00'): string
    {
        $markup = is_numeric($markupPercent) ? (string) $markupPercent : '0';
        $roundingCase = $rounding instanceof PriceRounding ? $rounding : (PriceRounding::tryFrom((string) $rounding) ?? PriceRounding::None);

        return 'US$ '.$costUsd.' → '.self::amounts($costUsd, $markup, $roundingCase);
    }

    public static function amounts(string $costUsd, string $markupPercent, PriceRounding $rounding): string
    {
        try {
            $prices = app(PriceCalculator::class)->pricesFor($costUsd, $markupPercent, $rounding);
        } catch (PricingException $exception) {
            return $exception->getMessage();
        }

        $currencies = Currency::query()->whereKey(array_keys($prices))->get()->keyBy('id');

        return collect($prices)
            ->map(function (int $amount, int $currencyId) use ($currencies): string {
                $currency = $currencies[$currencyId];
                $decimals = (int) $currency->decimal_places;

                return number_format($amount / (10 ** $decimals), $decimals, '.', '').' '.$currency->code;
            })
            ->implode(' · ');
    }
}
