<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Pricing;

use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Pricing\ListingPriceCalculator;
use Thayron\LunarCjDropshipping\Pricing\PriceCalculator;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ListingPriceCalculatorTest extends TestCase
{
    use CreatesLunarBaseline;

    private ListingPriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->calculator = app(ListingPriceCalculator::class);
    }

    public function test_usd_to_currency_rate(): void
    {
        $prices = app(PriceCalculator::class);

        $this->assertSame('0.925925925925', $prices->usdToCurrencyRate($this->eur));
        $this->assertSame('0.787037037036', $prices->usdToCurrencyRate($this->gbp));
    }

    public function test_recommends_markup_on_cost_plus_shipping_in_the_chosen_currency(): void
    {
        $this->assertSame('14.99', $this->calculator->recommend('3.47', '5.43', '100', PriceRounding::Ends99, $this->gbp));
        $this->assertSame('14.01', $this->calculator->recommend('3.47', '5.43', '100', PriceRounding::None, $this->gbp));
        $this->assertSame('16.48', $this->calculator->recommend('3.47', '5.43', '100', PriceRounding::None, $this->eur));
    }

    public function test_margin_is_a_percentage_of_the_price(): void
    {
        $this->assertSame('53.27', $this->calculator->margin('14.99', '3.47', '5.43', $this->gbp));
        $this->assertSame('-40.09', $this->calculator->margin('5.00', '3.47', '5.43', $this->gbp));
        $this->assertNull($this->calculator->margin('0', '3.47', '5.43', $this->gbp));
    }

    public function test_converts_usd_amounts(): void
    {
        $this->assertSame('21.65', $this->calculator->inCurrency('27.51', $this->gbp));
    }

    public function test_rounds_half_up(): void
    {
        $this->assertSame('1.24', ListingPriceCalculator::roundHalfUp('1.235', 2));
        $this->assertSame('-1.24', ListingPriceCalculator::roundHalfUp('-1.235', 2));
        $this->assertSame('2', ListingPriceCalculator::roundHalfUp('1.5', 0));
    }
}
