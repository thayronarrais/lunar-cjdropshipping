<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Support;

use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Attribute;
use Lunar\Models\AttributeGroup;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\TaxClass;

trait CreatesLunarBaseline
{
    protected Language $english;

    protected Language $french;

    protected Currency $eur;

    protected Currency $gbp;

    protected ?Currency $usd = null;

    protected Channel $channel;

    protected TaxClass $taxClass;

    protected ProductType $productType;

    /**
     * EUR default store with GBP and (optionally) USD, English + French.
     */
    protected function createLunarBaseline(bool $withUsd = true): void
    {
        $this->english = Language::factory()->create(['code' => 'en', 'name' => 'English', 'default' => true]);
        $this->french = Language::factory()->create(['code' => 'fr', 'name' => 'French', 'default' => false]);

        $this->eur = Currency::factory()->create([
            'code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'enabled' => true, 'default' => true,
        ]);
        $this->gbp = Currency::factory()->create([
            'code' => 'GBP', 'name' => 'Pound Sterling', 'exchange_rate' => 0.85, 'decimal_places' => 2, 'enabled' => true, 'default' => false,
        ]);

        if ($withUsd) {
            $this->usd = Currency::factory()->create([
                'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => 1.08, 'decimal_places' => 2, 'enabled' => true, 'default' => false,
            ]);
        }

        $this->channel = Channel::factory()->create(['name' => 'Webstore', 'handle' => 'webstore', 'default' => true]);
        CustomerGroup::factory()->create(['name' => 'Retail', 'handle' => 'retail', 'default' => true]);
        $this->taxClass = TaxClass::factory()->create(['name' => 'Default', 'default' => true]);

        $group = AttributeGroup::factory()->create([
            'attributable_type' => Product::morphName(),
            'name' => ['en' => 'Details'],
            'handle' => 'details',
            'position' => 1,
        ]);

        $attributes = collect(['name' => 'Name', 'description' => 'Description'])->map(fn (string $label, string $handle) => Attribute::factory()->create([
            'attribute_type' => Product::morphName(),
            'attribute_group_id' => $group->id,
            'position' => $handle === 'name' ? 1 : 2,
            'name' => ['en' => $label],
            'handle' => $handle,
            'section' => 'main',
            'type' => TranslatedText::class,
            'required' => $handle === 'name',
            'system' => true,
            'searchable' => true,
            'filterable' => false,
            'configuration' => [],
        ]));

        $this->productType = ProductType::factory()->create(['name' => 'CJ Products']);
        $this->productType->mappedAttributes()->attach($attributes->pluck('id')->all());
    }
}
