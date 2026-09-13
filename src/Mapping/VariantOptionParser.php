<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Mapping;

use Thayron\CjDropshipping\Data\Variant;

/**
 * Maps CJ "productKeyEn" (e.g. "Color-Size") and variant keys (e.g. "Black-XL") to Lunar options.
 */
final class VariantOptionParser
{
    public const FALLBACK_OPTION = 'Variant';

    /**
     * @param  list<Variant>  $variants
     * @return array{options: list<string>, values: array<string, list<string>>}
     */
    public function parse(mixed $productKeyEn, array $variants): array
    {
        if (count($variants) <= 1) {
            return ['options' => [], 'values' => []];
        }

        $names = $this->optionNames($productKeyEn);

        if ($names !== []) {
            $values = [];

            foreach ($variants as $variant) {
                $split = $this->split((string) $variant->key, count($names));

                if ($split === null) {
                    return $this->fallback($variants);
                }

                $values[$variant->id] = $split;
            }

            return ['options' => $names, 'values' => $values];
        }

        return $this->fallback($variants);
    }

    /**
     * @return list<string>
     */
    private function optionNames(mixed $productKeyEn): array
    {
        if (! is_string($productKeyEn) || trim($productKeyEn) === '') {
            return [];
        }

        $decoded = json_decode($productKeyEn, true);
        $parts = is_array($decoded) ? $decoded : explode('-', $productKeyEn);

        return array_values(array_filter(
            array_map(fn (mixed $part): string => is_scalar($part) ? trim((string) $part) : '', $parts),
            fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * @return list<string>|null
     */
    private function split(string $key, int $count): ?array
    {
        $parts = explode('-', $key);

        if (trim($key) === '' || count($parts) < $count) {
            return null;
        }

        $values = array_map('trim', [...array_slice($parts, 0, $count - 1), implode('-', array_slice($parts, $count - 1))]);

        return in_array('', $values, true) ? null : $values;
    }

    /**
     * @param  list<Variant>  $variants
     * @return array{options: list<string>, values: array<string, list<string>>}
     */
    private function fallback(array $variants): array
    {
        $values = [];

        foreach ($variants as $variant) {
            $label = trim((string) $variant->key);
            $values[$variant->id] = [$label !== '' ? $label : ($variant->sku ?? $variant->id)];
        }

        return ['options' => [self::FALLBACK_OPTION], 'values' => $values];
    }
}
