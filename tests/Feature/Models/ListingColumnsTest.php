<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Models;

use Illuminate\Database\UniqueConstraintViolationException;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\CandidateSource;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ListingColumnsTest extends TestCase
{
    use CreatesLunarBaseline;

    public function test_catalog_candidates_have_no_rule_and_store_a_listing(): void
    {
        $candidate = Candidate::create([
            'cj_product_id' => 'p-1',
            'source' => CandidateSource::Catalog,
            'name' => 'Jacket',
            'status' => CandidateStatus::Unavailable,
            'payload' => [],
            'listing' => ['name' => 'Jacket'],
            'listed_at' => now(),
            'discovered_at' => now(),
        ])->fresh();

        $this->assertNull($candidate->import_rule_id);
        $this->assertNull($candidate->importRule);
        $this->assertSame(CandidateSource::Catalog, $candidate->source);
        $this->assertSame(CandidateStatus::Unavailable, $candidate->status);
        $this->assertSame(['name' => 'Jacket'], $candidate->listing);
        $this->assertNotNull($candidate->listed_at);
    }

    public function test_a_cj_product_can_only_be_listed_once(): void
    {
        Candidate::create(['cj_product_id' => 'p-1', 'name' => 'A', 'status' => CandidateStatus::Pending, 'payload' => [], 'discovered_at' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);

        Candidate::create(['cj_product_id' => 'p-1', 'name' => 'B', 'status' => CandidateStatus::Pending, 'payload' => [], 'discovered_at' => now()]);
    }

    public function test_source_defaults_to_rule(): void
    {
        $candidate = Candidate::create(['cj_product_id' => 'p-1', 'name' => 'A', 'status' => CandidateStatus::Pending, 'payload' => [], 'discovered_at' => now()])->fresh();

        $this->assertSame(CandidateSource::Rule, $candidate->source);
    }

    public function test_links_default_to_unlocked_prices(): void
    {
        $this->createLunarBaseline();
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);

        $link = ProductLink::create(['cj_product_id' => 'p-1', 'lunar_product_id' => $product->id, 'markup_percent' => '0', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []])->fresh();

        $this->assertFalse($link->price_locked);
        $this->assertFalse($link->margin_at_risk);
        $this->assertSame([], $link->skipped_cj_variant_ids);

        $link->forceFill(['price_locked' => true, 'ship_from_country' => 'CN', 'ship_to_country' => 'GB', 'shipping_method' => 'CJPacket Ordinary', 'currency_code' => 'GBP', 'skipped_cj_variant_ids' => ['v-2']])->save();
        $this->assertSame(['v-2'], $link->fresh()->skipped_cj_variant_ids);

        $variantLink = VariantLink::create([
            'cj_variant_id' => 'v-1', 'cj_product_link_id' => $link->id, 'lunar_variant_id' => $product->variants()->create(['tax_class_id' => $this->taxClass->id, 'sku' => 'X'])->id,
            'shipping_cost_usd' => '5.43', 'price' => '24.99',
        ])->fresh();

        $this->assertSame('5.43', $variantLink->shipping_cost_usd);
        $this->assertSame('24.99', $variantLink->price);
    }

    public function test_config_defaults(): void
    {
        $this->assertSame(20, config('lunar-cjdropshipping.pricing.min_margin_percent'));
        $this->assertSame(21600, config('lunar-cjdropshipping.freight.cache_ttl'));
    }
}
