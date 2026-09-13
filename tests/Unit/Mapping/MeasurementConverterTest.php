<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Thayron\LunarCjDropshipping\Mapping\MeasurementConverter;

final class MeasurementConverterTest extends TestCase
{
    public function test_converts_grams_and_millimeters(): void
    {
        $converter = new MeasurementConverter;

        $this->assertSame('1.5800', $converter->gramsToKilograms('1580'));
        $this->assertSame('30.0000', $converter->millimetersToCentimeters('300'));
        $this->assertNull($converter->gramsToKilograms(null));
        $this->assertNull($converter->millimetersToCentimeters('not-a-number'));
    }
}
