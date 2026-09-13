<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Filament;

use Illuminate\Support\Facades\Cache;
use Thayron\LunarCjDropshipping\Filament\Support\CjCatalogOptions;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class CjCatalogOptionsTest extends TestCase
{
    public function test_returns_empty_options_and_does_not_throw_when_cj_fails(): void
    {
        config(['cjdropshipping.max_retries' => 0]);
        $cj = FakeCj::install($this->app);
        $cj->error(1600000, 'System busy');
        $cj->error(1600000, 'System busy');

        $this->assertSame([], CjCatalogOptions::categories());
        $this->assertSame([], CjCatalogOptions::countries());

        $this->assertFalse(Cache::has('lunar-cjdropshipping.categories'));
        $this->assertFalse(Cache::has('lunar-cjdropshipping.countries'));
    }

    public function test_loads_and_caches_category_and_country_options(): void
    {
        $cj = FakeCj::install($this->app);
        $cj->success([
            [
                'categoryFirstName' => 'Phones',
                'categoryFirstList' => [
                    [
                        'categorySecondName' => 'Accessories',
                        'categorySecondList' => [
                            ['categoryId' => 'cat-1', 'categoryName' => 'Cases'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['cat-1' => 'Phones › Accessories › Cases'], CjCatalogOptions::categories());
        $this->assertTrue(Cache::has('lunar-cjdropshipping.categories'));

        $requestCountAfterFirstCall = count($cj->requests());
        CjCatalogOptions::categories();
        $this->assertCount($requestCountAfterFirstCall, $cj->requests());

        $cj->success([
            ['areaId' => 2, 'areaEn' => 'US Warehouse', 'countryCode' => 'US', 'nameEn' => 'United States', 'disabled' => false],
            ['areaId' => 3, 'areaEn' => 'Disabled Warehouse', 'countryCode' => 'FR', 'nameEn' => 'France', 'disabled' => true],
        ]);

        $this->assertSame(['US' => 'United States (US)'], CjCatalogOptions::countries());
    }
}
