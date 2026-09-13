<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Lunar\Models\ProductVariant;

/**
 * @property int $id
 * @property string $cj_variant_id
 * @property int $cj_product_link_id
 * @property int $lunar_variant_id
 * @property string|null $cj_sku
 * @property string|null $cost_usd
 * @property string|null $shipping_cost_usd
 * @property string|null $price
 * @property int $stock
 * @property Carbon|null $last_synced_at
 * @property-read ProductLink $productLink
 * @property-read ProductVariant|null $variant
 */
class VariantLink extends Model
{
    protected $table = 'cj_variant_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:2',
            'shipping_cost_usd' => 'decimal:2',
            'price' => 'decimal:2',
            'stock' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductLink, $this>
     */
    public function productLink(): BelongsTo
    {
        return $this->belongsTo(ProductLink::class, 'cj_product_link_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'lunar_variant_id');
    }
}
