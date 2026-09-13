<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Thayron\CjDropshipping\Data\Variant;
use Thayron\LunarCjDropshipping\Mapping\VariantOptionParser;

final class VariantOptionParserTest extends TestCase
{
    public function test_single_variant_has_no_options(): void
    {
        $result = (new VariantOptionParser)->parse('Color', [$this->variant('v1', 'Black')]);

        $this->assertSame(['options' => [], 'values' => []], $result);
    }

    public function test_splits_two_options(): void
    {
        $result = (new VariantOptionParser)->parse('Color-Size', [
            $this->variant('v1', 'Black-XL'),
            $this->variant('v2', 'White-M'),
        ]);

        $this->assertSame(['Color', 'Size'], $result['options']);
        $this->assertSame(['v1' => ['Black', 'XL'], 'v2' => ['White', 'M']], $result['values']);
    }

    public function test_keeps_hyphens_in_the_last_value(): void
    {
        $result = (new VariantOptionParser)->parse('Color-Size', [
            $this->variant('v1', 'Black-2-3 Years'),
            $this->variant('v2', 'White-4-5 Years'),
        ]);

        $this->assertSame(['v1' => ['Black', '2-3 Years'], 'v2' => ['White', '4-5 Years']], $result['values']);
    }

    public function test_accepts_json_encoded_option_names(): void
    {
        $result = (new VariantOptionParser)->parse('["Color","Size"]', [
            $this->variant('v1', 'Black-XL'),
            $this->variant('v2', 'White-M'),
        ]);

        $this->assertSame(['Color', 'Size'], $result['options']);
    }

    public function test_falls_back_to_a_single_variant_option_when_keys_do_not_match(): void
    {
        $result = (new VariantOptionParser)->parse('Color-Size-Style', [
            $this->variant('v1', 'Black-XL'),
            $this->variant('v2', 'White'),
        ]);

        $this->assertSame(['Variant'], $result['options']);
        $this->assertSame(['v1' => ['Black-XL'], 'v2' => ['White']], $result['values']);
    }

    public function test_falls_back_when_option_names_are_missing(): void
    {
        $result = (new VariantOptionParser)->parse(null, [
            $this->variant('v1', 'Black'),
            $this->variant('v2', null, 'SKU-2'),
        ]);

        $this->assertSame(['Variant'], $result['options']);
        $this->assertSame(['v1' => ['Black'], 'v2' => ['SKU-2']], $result['values']);
    }

    private function variant(string $id, ?string $key, ?string $sku = null): Variant
    {
        return Variant::fromArray(['vid' => $id, 'variantKey' => $key, 'variantSku' => $sku ?? 'SKU-'.$id]);
    }
}
