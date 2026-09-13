<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Unit\Pricing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thayron\LunarCjDropshipping\Pricing\CostParser;

final class CostParserTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function costs(): array
    {
        return [
            'single' => ['11.85', '11.85'],
            'integer' => ['3', '3.00'],
            'range' => ['1.20-3.50', '1.20'],
            'spaced range' => ['8.62 -- 8.13', '8.13'],
            'null' => [null, null],
            'no number' => ['n/a', null],
        ];
    }

    #[DataProvider('costs')]
    public function test_returns_the_lowest_cost(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, CostParser::lowest($input));
    }
}
