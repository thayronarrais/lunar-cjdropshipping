<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Integration;

use Lunar\Models\Price;
use Lunar\Models\Product;
use PHPUnit\Framework\Attributes\Group;
use Thayron\LunarCjDropshipping\Actions\DiscoverCandidates;
use Thayron\LunarCjDropshipping\Actions\ImportProduct;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

/**
 * Hits the real CJdropshipping API: CJ_API_KEY=... vendor/bin/phpunit --group live
 */
#[Group('live')]
final class LiveImportTest extends TestCase
{
    use CreatesLunarBaseline;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cjdropshipping.api_key', (string) getenv('CJ_API_KEY'));
        $app['config']->set('lunar-cjdropshipping.requests_per_second', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_string(getenv('CJ_API_KEY')) || getenv('CJ_API_KEY') === '') {
            $this->markTestSkipped('Set CJ_API_KEY to run the live CJdropshipping tests.');
        }

        $this->app->instance(Throttle::class, new Throttle(1));
    }

    public function test_discovers_and_imports_a_real_product(): void
    {
        $this->createLunarBaseline();
        $rule = ImportRule::create([
            'name' => 'Live',
            'keyword' => 'phone case',
            'min_stock' => 1,
            'markup_percent' => '100',
            'rounding' => PriceRounding::Ends90,
            'product_type_id' => $this->productType->id,
            'max_pages' => 1,
        ]);

        $stats = app(DiscoverCandidates::class)->handle($rule);
        $this->assertGreaterThan(0, $stats['created']);

        $result = app(ImportProduct::class)->handle(Candidate::query()->firstOrFail());

        $product = Product::query()->findOrFail($result->link->lunar_product_id);
        $this->assertSame('draft', $product->status);
        $this->assertGreaterThan(0, $product->variants()->count());
        $this->assertGreaterThan(0, Price::query()->count());
        $this->assertNotEmpty($result->imageUrls);
    }
}
