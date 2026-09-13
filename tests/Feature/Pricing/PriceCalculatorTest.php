<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Pricing;

use Lunar\Models\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\PricingException;
use Thayron\LunarCjDropshipping\Pricing\PriceCalculator;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class PriceCalculatorTest extends TestCase
{
    use CreatesLunarBaseline;

    public function test_converts_through_the_usd_currency_rate(): void
    {
        $this->createLunarBaseline();

        $prices = (new PriceCalculator)->pricesFor('10.00', '100', PriceRounding::Ends90);

        $this->assertSame([
            $this->eur->id => 1890,
            $this->gbp->id => 1590,
            $this->usd->id => 2090,
        ], $prices);
    }

    /**
     * @return array<string, array{PriceRounding, int, int}>
     */
    public static function roundings(): array
    {
        // cost 10.00 USD, markup 100% => EUR 18.5185..., GBP 15.7407...
        return [
            'none' => [PriceRounding::None, 1852, 1574],
            'ends 90' => [PriceRounding::Ends90, 1890, 1590],
            'ends 99' => [PriceRounding::Ends99, 1899, 1599],
            'whole' => [PriceRounding::Whole, 1900, 1600],
        ];
    }

    #[DataProvider('roundings')]
    public function test_applies_rounding(PriceRounding $rounding, int $expectedEur, int $expectedGbp): void
    {
        $this->createLunarBaseline();
        $calculator = new PriceCalculator;

        $this->assertSame($expectedEur, $calculator->priceFor('10.00', '100', $rounding, $this->eur));
        $this->assertSame($expectedGbp, $calculator->priceFor('10.00', '100', $rounding, $this->gbp));
    }

    public function test_ends_90_moves_up_when_the_price_is_already_whole(): void
    {
        $this->createLunarBaseline();

        // 21.60 USD * (1/1.08) = 20.00 EUR exactly -> 20.90
        $this->assertSame(2090, (new PriceCalculator)->priceFor('21.60', '0', PriceRounding::Ends90, $this->eur));
    }

    public function test_uses_the_configured_rate_when_there_is_no_usd_currency(): void
    {
        $this->createLunarBaseline(withUsd: false);
        config(['lunar-cjdropshipping.pricing.usd_to_default_rate' => '0.5']);

        $this->assertSame([
            $this->eur->id => 1000,
            $this->gbp->id => 850,
        ], (new PriceCalculator)->pricesFor('10.00', '100', PriceRounding::None));
    }

    public function test_usd_default_store_uses_rate_one(): void
    {
        $this->createLunarBaseline(withUsd: false);
        $this->eur->update(['default' => false]);
        $usd = Currency::factory()->create(['code' => 'USD', 'exchange_rate' => 1, 'decimal_places' => 2, 'enabled' => true, 'default' => true]);

        $this->assertSame('1', (new PriceCalculator)->usdRate());
        $this->assertSame(2000, (new PriceCalculator)->priceFor('10.00', '100', PriceRounding::None, $usd));
    }

    public function test_zero_decimal_currency_rounds_ends_to_whole(): void
    {
        $this->createLunarBaseline();
        $yen = Currency::factory()->create(['code' => 'JPY', 'exchange_rate' => 160.4, 'decimal_places' => 0, 'enabled' => true, 'default' => false]);

        // 20 USD / 1.08 * 160.4 = 2970.37 -> ceil 2971
        $this->assertSame(2971, (new PriceCalculator)->priceFor('10.00', '100', PriceRounding::Ends90, $yen));
    }

    public function test_throws_when_no_usd_rate_is_available(): void
    {
        $this->createLunarBaseline(withUsd: false);
        config(['lunar-cjdropshipping.pricing.usd_to_default_rate' => null]);

        $this->expectException(PricingException::class);

        (new PriceCalculator)->usdRate();
    }

    public function test_ignores_disabled_currencies(): void
    {
        $this->createLunarBaseline();
        $this->gbp->update(['enabled' => false]);

        $this->assertArrayNotHasKey($this->gbp->id, (new PriceCalculator)->pricesFor('10.00', '0', PriceRounding::None));
    }
}
