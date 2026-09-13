<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Catalog;

use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Actions\AddCandidateFromCatalog;
use Thayron\LunarCjDropshipping\Actions\SearchCatalog;
use Thayron\LunarCjDropshipping\Enums\CandidateSource;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class SearchCatalogTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cj = FakeCj::install($this->app);
    }

    public function test_searches_with_filters_and_caches_the_page(): void
    {
        $this->cj->fixture('list-v2-page');
        $filters = ['keyword' => 'case', 'category_id' => 'cat-1', 'country_code' => 'us', 'min_price' => '1', 'max_price' => null];

        $result = app(SearchCatalog::class)->handle($filters, 2);
        app(SearchCatalog::class)->handle($filters, 2);

        $this->assertCount(1, $this->cj->requests());
        $query = $this->cj->queryAt(0);
        $this->assertSame('case', $query['keyWord']);
        $this->assertSame('cat-1', $query['categoryId']);
        $this->assertSame('US', $query['countryCode']);
        $this->assertSame('1', $query['startSellPrice']);
        $this->assertArrayNotHasKey('endSellPrice', $query);
        $this->assertSame('2', $query['page']);
        $this->assertSame('24', $query['size']);
        $this->assertSame('p-100', $result->items[0]->id);
    }

    public function test_adds_a_catalog_product_once(): void
    {
        $this->cj->fixture('list-v2-page');
        $summary = app(SearchCatalog::class)->handle([], 1)->items[0];

        $candidate = app(AddCandidateFromCatalog::class)->handle($summary);
        $again = app(AddCandidateFromCatalog::class)->handle($summary);

        $this->assertNotNull($candidate);
        $this->assertNull($again);
        $stored = Candidate::query()->sole();
        $this->assertNull($stored->import_rule_id);
        $this->assertSame(CandidateSource::Catalog, $stored->source);
        $this->assertSame(CandidateStatus::Pending, $stored->status);
        $this->assertSame('Magnetic Phone Case', $stored->name);
        $this->assertSame('11.85', $stored->cost_usd);
    }

    public function test_marks_already_imported_products(): void
    {
        $this->createLunarBaseline();
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        ProductLink::create(['cj_product_id' => 'p-100', 'lunar_product_id' => $product->id, 'markup_percent' => '0', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []]);
        $this->cj->fixture('list-v2-page');

        $candidate = app(AddCandidateFromCatalog::class)->handle(app(SearchCatalog::class)->handle([], 1)->items[0]);

        $this->assertSame(CandidateStatus::Imported, $candidate?->status);
        $this->assertSame($product->id, $candidate?->lunar_product_id);
    }
}
