<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Logistics;

use Thayron\LunarCjDropshipping\Logistics\FreightQuoter;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class FreightQuoterTest extends TestCase
{
    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cj = FakeCj::install($this->app);
    }

    public function test_quotes_one_variant_per_distinct_weight(): void
    {
        $this->cj
            ->success([
                ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 5.43, 'logisticAging' => '7-12'],
                ['logisticName' => 'USPS+', 'logisticPrice' => 9.10, 'logisticAging' => '4-8'],
            ])
            ->success([
                ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '2.10', 'logisticAging' => '7-12'],
            ]);

        $quote = app(FreightQuoter::class)->quote('p-100', 'CN', 'GB', ['v-1' => '1580', 'v-2' => '120', 'v-3' => '1580']);

        $this->assertSame(['logistic/freightCalculate', 'logistic/freightCalculate'], $this->cj->paths());
        $this->assertSame(['startCountryCode' => 'CN', 'endCountryCode' => 'GB', 'products' => [['vid' => 'v-1', 'quantity' => 1]]], $this->cj->jsonAt(0));
        $this->assertSame(['startCountryCode' => 'CN', 'endCountryCode' => 'GB', 'products' => [['vid' => 'v-2', 'quantity' => 1]]], $this->cj->jsonAt(1));

        $this->assertSame('5.43', $quote['v-1']['CJPacket Ordinary']->priceUsd);
        $this->assertSame('5.43', $quote['v-3']['CJPacket Ordinary']->priceUsd);
        $this->assertSame('2.10', $quote['v-2']['CJPacket Ordinary']->priceUsd);
        $this->assertArrayNotHasKey('USPS+', $quote['v-2']);

        $this->assertSame(['CJPacket Ordinary'], FreightQuoter::commonMethods($quote, ['v-1', 'v-2']));
        $this->assertSame(['CJPacket Ordinary', 'USPS+'], FreightQuoter::commonMethods($quote, ['v-1', 'v-3']));
        $this->assertSame([], FreightQuoter::commonMethods($quote, []));
    }

    public function test_caches_quotes(): void
    {
        $this->cj->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 5.43, 'logisticAging' => '7-12']]);

        app(FreightQuoter::class)->quote('p-100', 'CN', 'GB', ['v-1' => '1580']);
        $again = app(FreightQuoter::class)->quote('p-100', 'CN', 'GB', ['v-1' => '1580']);

        $this->assertCount(1, $this->cj->requests());
        $this->assertSame('5.43', $again['v-1']['CJPacket Ordinary']->priceUsd);
    }
}
