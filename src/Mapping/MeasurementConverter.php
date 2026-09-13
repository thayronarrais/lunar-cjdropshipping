<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Mapping;

/**
 * CJ sends weights in grams and dimensions in millimeters; the store uses kg and cm.
 */
final class MeasurementConverter
{
    public function gramsToKilograms(?string $grams): ?string
    {
        return self::divide($grams, '1000');
    }

    public function millimetersToCentimeters(?string $millimeters): ?string
    {
        return self::divide($millimeters, '10');
    }

    private static function divide(?string $value, string $divisor): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return bcdiv($value, $divisor, 4);
    }
}
