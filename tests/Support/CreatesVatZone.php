<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Support;

use Lunar\Models\Country;
use Lunar\Models\TaxRate;
use Lunar\Models\TaxRateAmount;
use Lunar\Models\TaxZone;
use Lunar\Models\TaxZoneCountry;

/**
 * Requires CreatesLunarBaseline (already created) for $this->taxClass.
 */
trait CreatesVatZone
{
    protected function createVatZone(string $iso2 = 'GB', string $percent = '20', bool $inclusive = true): void
    {
        $country = Country::query()->where('iso2', $iso2)->first()
            ?? Country::factory()->create(['iso2' => $iso2, 'iso3' => $iso2.'X', 'name' => "Country {$iso2}"]);

        $zone = TaxZone::factory()->create([
            'name' => "{$iso2} VAT",
            'zone_type' => 'country',
            'price_display' => $inclusive ? 'tax_inclusive' : 'tax_exclusive',
            'active' => true,
            'default' => false,
        ]);

        TaxZoneCountry::factory()->create(['tax_zone_id' => $zone->id, 'country_id' => $country->id]);

        $rate = TaxRate::factory()->create(['tax_zone_id' => $zone->id, 'name' => 'VAT', 'priority' => 1]);

        TaxRateAmount::factory()->create(['tax_rate_id' => $rate->id, 'tax_class_id' => $this->taxClass->id, 'percentage' => $percent]);
    }
}
