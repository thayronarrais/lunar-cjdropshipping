<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

use Lunar\Models\TaxClass;
use Lunar\Models\TaxRate;
use Lunar\Models\TaxRateAmount;
use Lunar\Models\TaxZone;

/**
 * VAT for a destination country, read from Lunar tax zones (default tax class). Memoized per instance.
 */
final class VatResolver
{
    /** @var array<string, Vat> */
    private array $resolved = [];

    public function forCountry(?string $iso2): Vat
    {
        $code = strtoupper(trim((string) $iso2));

        if ($code === '') {
            return Vat::none();
        }

        return $this->resolved[$code] ??= $this->resolve($code);
    }

    private function resolve(string $iso2): Vat
    {
        /** @var TaxClass|null $taxClass */
        $taxClass = TaxClass::getDefault();

        if ($taxClass === null) {
            return Vat::none();
        }

        $zones = TaxZone::query()
            ->where('active', true)
            ->whereHas('countries.country', fn ($query) => $query->where('iso2', $iso2))
            ->with('taxRates.taxRateAmounts')
            ->orderBy('id')
            ->get();

        $zone = $zones->firstWhere('price_display', 'tax_inclusive') ?? $zones->first();

        if ($zone === null) {
            return Vat::none();
        }

        $percent = '0';

        /** @var iterable<int, TaxRate> $taxRates */
        $taxRates = $zone->taxRates;

        foreach ($taxRates as $rate) {
            /** @var iterable<int, TaxRateAmount> $taxRateAmounts */
            $taxRateAmounts = $rate->taxRateAmounts;

            foreach ($taxRateAmounts as $amount) {
                if ((int) $amount->tax_class_id === (int) $taxClass->id) {
                    $percent = bcadd($percent, (string) $amount->percentage, 4);
                }
            }
        }

        return new Vat($percent, $zone->price_display === 'tax_inclusive');
    }
}
