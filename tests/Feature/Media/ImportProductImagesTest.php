<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Actions\ImportProductImages;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Jobs\ImportProductImagesJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ImportProductImagesTest extends TestCase
{
    use CreatesLunarBaseline;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAMAASsJTYQAAAAASUVORK5CYII=';

    private ProductLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->createLunarBaseline();

        $product = Product::factory()->create(['product_type_id' => $this->productType->id, 'status' => 'draft']);
        $this->link = ProductLink::create([
            'cj_product_id' => 'p-100',
            'lunar_product_id' => $product->id,
            'markup_percent' => '100',
            'rounding' => PriceRounding::None,
            'new_cj_variant_ids' => [],
        ]);
    }

    public function test_downloads_images_and_marks_the_first_as_primary(): void
    {
        Http::fake(['cf.cjdropshipping.com/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);

        $failures = app(ImportProductImages::class)->handle($this->link, [
            'https://cf.cjdropshipping.com/case-1.png',
            'https://cf.cjdropshipping.com/case-2.png',
            'https://cf.cjdropshipping.com/case-1.png',
        ]);

        $media = $this->link->product->fresh()->getMedia('images');
        $this->assertSame([], $failures);
        $this->assertCount(2, $media);
        $this->assertTrue($media[0]->getCustomProperty('primary'));
        $this->assertFalse($media[1]->getCustomProperty('primary'));
        $this->assertSame('https://cf.cjdropshipping.com/case-1.png', $media[0]->getCustomProperty('cj_source_url'));
        $this->assertNull($this->link->fresh()->sync_error);
    }

    public function test_does_not_duplicate_images_on_reimport(): void
    {
        Http::fake(['cf.cjdropshipping.com/*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png'])]);
        $urls = ['https://cf.cjdropshipping.com/case-1.png'];

        app(ImportProductImages::class)->handle($this->link, $urls);
        app(ImportProductImages::class)->handle($this->link, $urls);

        $this->assertCount(1, $this->link->product->fresh()->getMedia('images'));
        Http::assertSentCount(1);
    }

    public function test_keeps_successful_images_and_records_failures(): void
    {
        Http::fake([
            'cf.cjdropshipping.com/ok.png' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            'cf.cjdropshipping.com/missing.png' => Http::response('', 404),
        ]);

        $failures = app(ImportProductImages::class)->handle($this->link, [
            'https://cf.cjdropshipping.com/missing.png',
            'https://cf.cjdropshipping.com/ok.png',
        ]);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('missing.png', $failures[0]);
        $media = $this->link->product->fresh()->getMedia('images');
        $this->assertCount(1, $media);
        $this->assertTrue($media[0]->getCustomProperty('primary'));
        $this->assertStringContainsString('missing.png', (string) $this->link->fresh()->sync_error);
    }

    public function test_job_throws_to_retry_when_an_image_fails(): void
    {
        Http::fake(['cf.cjdropshipping.com/*' => Http::response('', 500)]);

        $this->expectException(ImportException::class);

        (new ImportProductImagesJob($this->link, ['https://cf.cjdropshipping.com/case-1.png']))
            ->handle(app(ImportProductImages::class));
    }
}
