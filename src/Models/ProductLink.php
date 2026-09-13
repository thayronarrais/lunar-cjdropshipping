<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;

/**
 * @property int $id
 * @property string $cj_product_id
 * @property int $lunar_product_id
 * @property int|null $import_rule_id
 * @property string $markup_percent
 * @property PriceRounding $rounding
 * @property string|null $country_code
 * @property CjProductStatus $cj_status
 * @property int $not_found_count
 * @property list<string> $new_cj_variant_ids
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $sync_error
 * @property-read Product|null $product
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VariantLink> $variantLinks
 */
class ProductLink extends Model
{
    protected $table = 'cj_product_links';

    protected $guarded = [];

    protected $attributes = [
        'not_found_count' => 0,
        'cj_status' => 'active',
        'new_cj_variant_ids' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'markup_percent' => 'decimal:2',
            'rounding' => PriceRounding::class,
            'cj_status' => CjProductStatus::class,
            'not_found_count' => 'integer',
            'new_cj_variant_ids' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'lunar_product_id');
    }

    /**
     * @return BelongsTo<ImportRule, $this>
     */
    public function importRule(): BelongsTo
    {
        return $this->belongsTo(ImportRule::class);
    }

    /**
     * @return HasMany<VariantLink, $this>
     */
    public function variantLinks(): HasMany
    {
        return $this->hasMany(VariantLink::class, 'cj_product_link_id');
    }
}
