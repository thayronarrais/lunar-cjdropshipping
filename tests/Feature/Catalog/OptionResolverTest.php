<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Catalog;

use Lunar\Models\ProductOptionValue;
use Thayron\LunarCjDropshipping\Catalog\OptionResolver;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class OptionResolverTest extends TestCase
{
    use CreatesLunarBaseline;

    public function test_value_reuses_an_existing_value_by_default_language_name_and_creates_distinct_values(): void
    {
        $this->createLunarBaseline();
        $resolver = app(OptionResolver::class);
        $option = $resolver->option('Color');

        $black = $resolver->value($option, 'Black');
        $blackAgain = $resolver->value($option, 'Black');
        $navy = $resolver->value($option, 'Navy Blue');

        $this->assertTrue($black->is($blackAgain));
        $this->assertFalse($black->is($navy));
        $this->assertSame(2, ProductOptionValue::query()->count());
        $this->assertSame('Black', $blackAgain->name['en']);
        $this->assertSame('Navy Blue', $navy->name['en']);
    }
}
