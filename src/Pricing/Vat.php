<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

/**
 * VAT applied to a sale price in one destination country.
 */
final readonly class Vat
{
    /**
     * @param  string  $percent  Rate in percent, e.g. "20".
     * @param  bool  $inclusive  Whether shop prices already include the VAT.
     */
    public function __construct(
        public string $percent,
        public bool $inclusive,
    ) {}

    public static function none(): self
    {
        return new self('0', false);
    }
}
