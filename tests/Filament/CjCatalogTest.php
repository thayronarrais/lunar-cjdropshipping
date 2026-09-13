<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Thayron\LunarCjDropshipping\Enums\CandidateSource;
use Thayron\LunarCjDropshipping\Filament\Pages\CjCatalog;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Tests\FilamentTestCase;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;

final class CjCatalogTest extends FilamentTestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->actingAsStaff();
        Cache::put('lunar-cjdropshipping.categories', ['cat-1' => 'Phones › Accessories › Cases'], 3600);
        Cache::put('lunar-cjdropshipping.countries', ['US' => 'United States (US)'], 3600);
        $this->cj = FakeCj::install($this->app);
    }

    public function test_shows_products_and_adds_one_to_the_import_list(): void
    {
        $this->cj->fixture('list-v2-page');

        Livewire::test(CjCatalog::class)
            ->assertSee('Magnetic Phone Case')
            ->assertSee('Cable Organizer')
            ->call('addToList', 'p-100')
            ->assertNotified(__('lunar-cjdropshipping::admin.catalog.added'))
            ->assertSee(__('lunar-cjdropshipping::admin.catalog.in_list'))
            ->call('addToList', 'p-100')
            ->assertNotified(__('lunar-cjdropshipping::admin.catalog.already'));

        $candidate = Candidate::query()->sole();
        $this->assertSame('p-100', $candidate->cj_product_id);
        $this->assertSame(CandidateSource::Catalog, $candidate->source);
        $this->assertCount(1, $this->cj->requests());
    }

    public function test_search_sends_the_filters(): void
    {
        $this->cj->fixture('list-v2-page')->fixture('list-v2-page');

        Livewire::test(CjCatalog::class)
            ->fillForm(['keyword' => 'case', 'country_code' => 'US'])
            ->call('search');

        $query = $this->cj->queryAt(1);
        $this->assertSame('case', $query['keyWord']);
        $this->assertSame('US', $query['countryCode']);
    }

    public function test_shows_an_error_when_cj_fails(): void
    {
        config(['cjdropshipping.max_retries' => 0]);
        $this->cj->error(1600000, 'System busy');

        Livewire::test(CjCatalog::class)->assertSee('System busy');
    }
}
