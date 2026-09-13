<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Support;

use Thayron\LunarCjDropshipping\Actions\ImportProduct;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;

/**
 * Requires CreatesLunarBaseline (already created) and a FakeCj instance in $this->cj.
 */
trait ImportsFixtureProduct
{
    /**
     * @param  array<string, mixed>  $ruleAttributes
     */
    protected function importFixtureProduct(array $ruleAttributes = []): ProductLink
    {
        $rule = ImportRule::create([
            'name' => 'Rule',
            'keyword' => 'case',
            'markup_percent' => '100',
            'rounding' => PriceRounding::Ends90,
            'product_type_id' => $this->productType->id,
            ...$ruleAttributes,
        ]);

        $candidate = Candidate::create([
            'import_rule_id' => $rule->id,
            'cj_product_id' => 'p-100',
            'name' => 'Magnetic Phone Case',
            'status' => CandidateStatus::Approved,
            'payload' => [],
            'discovered_at' => now(),
        ]);

        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        return app(ImportProduct::class)->handle($candidate)->link;
    }
}
