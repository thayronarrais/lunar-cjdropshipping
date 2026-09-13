<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Models;

use Illuminate\Database\QueryException;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ModelsTest extends TestCase
{
    use CreatesLunarBaseline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
    }

    public function test_import_rule_casts_and_relations(): void
    {
        $rule = ImportRule::create([
            'name' => 'Phone cases',
            'category_ids' => ['cat-1', 'cat-2'],
            'markup_percent' => '120.5',
            'rounding' => PriceRounding::Ends90,
            'product_type_id' => $this->productType->id,
            'max_pages' => 3,
        ])->fresh();

        $this->assertTrue($rule->is_active);
        $this->assertSame(['cat-1', 'cat-2'], $rule->category_ids);
        $this->assertSame('120.50', $rule->markup_percent);
        $this->assertSame(PriceRounding::Ends90, $rule->rounding);
        $this->assertSame(0, $rule->min_stock);
        $this->assertTrue($rule->productType->is($this->productType));
    }

    public function test_candidate_belongs_to_rule_and_is_unique_per_rule(): void
    {
        $rule = $this->rule();
        $candidate = Candidate::create([
            'import_rule_id' => $rule->id,
            'cj_product_id' => 'pid-1',
            'name' => 'Case',
            'cost_usd' => '3.5',
            'status' => CandidateStatus::Pending,
            'payload' => ['id' => 'pid-1'],
            'discovered_at' => now(),
        ])->fresh();

        $this->assertSame(CandidateStatus::Pending, $candidate->status);
        $this->assertSame('3.50', $candidate->cost_usd);
        $this->assertSame(['id' => 'pid-1'], $candidate->payload);
        $this->assertTrue($candidate->importRule->is($rule));

        $this->expectException(QueryException::class);

        Candidate::create([
            'import_rule_id' => $rule->id,
            'cj_product_id' => 'pid-1',
            'name' => 'Duplicate',
            'status' => CandidateStatus::Pending,
            'payload' => [],
            'discovered_at' => now(),
        ]);
    }

    public function test_product_and_variant_links(): void
    {
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'tax_class_id' => $this->taxClass->id]);

        $link = ProductLink::create([
            'cj_product_id' => 'pid-1',
            'lunar_product_id' => $product->id,
            'import_rule_id' => $this->rule()->id,
            'markup_percent' => '100',
            'rounding' => PriceRounding::None,
            'cj_status' => CjProductStatus::Active,
            'new_cj_variant_ids' => [],
        ])->fresh();

        $variantLink = VariantLink::create([
            'cj_variant_id' => 'vid-1',
            'cj_product_link_id' => $link->id,
            'lunar_variant_id' => $variant->id,
            'cost_usd' => '10',
            'stock' => 7,
        ]);

        $this->assertSame(CjProductStatus::Active, $link->cj_status);
        $this->assertSame([], $link->new_cj_variant_ids);
        $this->assertSame(0, $link->not_found_count);
        $this->assertTrue($link->product->is($product));
        $this->assertTrue($link->variantLinks->first()->is($variantLink));
        $this->assertTrue($variantLink->variant->is($variant));
        $this->assertSame('10.00', $variantLink->fresh()->cost_usd);
    }

    public function test_product_link_is_unique_per_cj_product(): void
    {
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        $attributes = [
            'cj_product_id' => 'pid-1',
            'lunar_product_id' => $product->id,
            'markup_percent' => '0',
            'rounding' => PriceRounding::None,
            'cj_status' => CjProductStatus::Active,
            'new_cj_variant_ids' => [],
        ];

        ProductLink::create($attributes);

        $this->expectException(QueryException::class);

        ProductLink::create($attributes);
    }

    private function rule(): ImportRule
    {
        return ImportRule::create([
            'name' => 'Rule',
            'keyword' => 'case',
            'markup_percent' => '100',
            'rounding' => PriceRounding::None,
            'product_type_id' => $this->productType->id,
        ]);
    }
}
