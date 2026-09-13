<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Catalog;

use Illuminate\Support\Str;
use Lunar\Models\Language;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;

final class OptionResolver
{
    /**
     * @return array<string, string>
     */
    public function translated(string $value): array
    {
        $codes = Language::query()->orderBy('id')->pluck('code')->all();

        return $codes === [] ? ['en' => $value] : array_fill_keys($codes, $value);
    }

    public function option(string $name): ProductOption
    {
        return ProductOption::query()->firstOrCreate(
            ['handle' => Str::slug($name)],
            ['name' => $this->translated($name), 'label' => $this->translated($name), 'shared' => true],
        );
    }

    public function value(ProductOption $option, string $name): ProductOptionValue
    {
        $locale = Language::getDefault()?->code ?? 'en';

        $existing = $option->values()->get()->first(
            fn (ProductOptionValue $value): bool => ($value->name[$locale] ?? null) === $name,
        );

        return $existing ?? $option->values()->create([
            'name' => $this->translated($name),
            'position' => $option->values()->count() + 1,
        ]);
    }
}
