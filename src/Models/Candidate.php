<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;

/**
 * @property int $id
 * @property int $import_rule_id
 * @property string $cj_product_id
 * @property string|null $cj_sku
 * @property string $name
 * @property string|null $image_url
 * @property string|null $cost_usd
 * @property int|null $warehouse_stock
 * @property string|null $cj_category_id
 * @property CandidateStatus $status
 * @property string|null $error
 * @property int|null $lunar_product_id
 * @property array<string, mixed> $payload
 * @property Carbon $discovered_at
 * @property-read ImportRule $importRule
 */
class Candidate extends Model
{
    protected $table = 'cj_candidates';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CandidateStatus::class,
            'cost_usd' => 'decimal:2',
            'warehouse_stock' => 'integer',
            'payload' => 'array',
            'discovered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ImportRule, $this>
     */
    public function importRule(): BelongsTo
    {
        return $this->belongsTo(ImportRule::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'lunar_product_id');
    }
}
