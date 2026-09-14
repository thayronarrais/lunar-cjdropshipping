<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Pricing;

use Thayron\LunarCjDropshipping\Pricing\VatResolver;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesVatZone;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class VatResolverTest extends TestCase
{
    use CreatesLunarBaseline;
    use CreatesVatZone;

    public function test_returns_the_inclusive_rate_for_the_destination_country(): void
    {
        $this->createLunarBaseline();
        $this->createVatZone('GB', '20', inclusive: true);

        $vat = app(VatResolver::class)->forCountry('gb');

        $this->assertSame(0, bccomp($vat->percent, '20', 4));
        $this->assertTrue($vat->inclusive);
    }

    public function test_prefers_the_inclusive_zone_when_a_country_has_several(): void
    {
        $this->createLunarBaseline();
        $this->createVatZone('GB', '20', inclusive: false);
        $this->createVatZone('GB', '20', inclusive: true);

        $this->assertTrue(app(VatResolver::class)->forCountry('GB')->inclusive);
    }

    public function test_an_exclusive_zone_is_not_inclusive(): void
    {
        $this->createLunarBaseline();
        $this->createVatZone('GB', '20', inclusive: false);

        $this->assertFalse(app(VatResolver::class)->forCountry('GB')->inclusive);
    }

    public function test_returns_no_vat_for_an_unknown_or_empty_country(): void
    {
        $this->createLunarBaseline();

        $unknown = app(VatResolver::class)->forCountry('ZZ');
        $empty = app(VatResolver::class)->forCountry(null);

        $this->assertSame(['0', false], [$unknown->percent, $unknown->inclusive]);
        $this->assertSame(['0', false], [$empty->percent, $empty->inclusive]);
    }
}
