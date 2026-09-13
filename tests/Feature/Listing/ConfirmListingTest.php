<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Listing;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Thayron\LunarCjDropshipping\Actions\ConfirmListing;
use Thayron\LunarCjDropshipping\Enums\CandidateSource;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\ListingException;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Listing\Listing;
use Thayron\LunarCjDropshipping\Listing\ListingVariant;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ConfirmListingTest extends TestCase
{
    use CreatesLunarBaseline;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createLunarBaseline();
        Queue::fake();  // Re-fake to clear any jobs from setup
        $this->candidate = Candidate::create([
            'cj_product_id' => 'p-100', 'source' => CandidateSource::Catalog, 'name' => 'Magnetic Phone Case',
            'status' => CandidateStatus::Pending, 'payload' => [], 'discovered_at' => now(),
        ]);
    }

    public function test_round_trips_the_listing_array(): void
    {
        $listing = $this->listing();

        $this->assertEquals($listing, Listing::fromArray($listing->toArray()));
        $this->assertSame(['v-2'], $listing->skippedVariantIds());
        $this->assertSame('v-1', $listing->selectedVariants()[0]->vid);
        $this->assertSame([
            'name', 'ship_from_country', 'ship_to_country', 'currency_code', 'shipping_method', 'markup_percent',
            'rounding', 'product_type_id', 'brand_id', 'collection_id', 'variants',
        ], array_keys($listing->toArray()));
    }

    public function test_confirms_and_queues_the_import(): void
    {
        app(ConfirmListing::class)->handle($this->candidate, $this->listing(), false);

        $candidate = $this->candidate->fresh();
        $this->assertSame(CandidateStatus::Approved, $candidate->status);
        $this->assertSame('Plaid Dog Jacket', $candidate->name);
        $this->assertSame($this->listing()->toArray(), $candidate->listing);
        $this->assertNotNull($candidate->listed_at);
        Queue::assertPushed(ImportProductJob::class, fn (ImportProductJob $job): bool => $job->candidate->is($candidate));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidListings(): array
    {
        return [
            'empty name' => [['name' => '  '], 'name_required'],
            'long name' => [['name' => str_repeat('a', 256)], 'name_too_long'],
            'bad origin' => [['ship_from_country' => 'China'], 'countries_required'],
            'missing destination' => [['ship_to_country' => ''], 'countries_required'],
            'disabled currency' => [['currency_code' => 'JPY'], 'currency_invalid'],
            'no method' => [['shipping_method' => ''], 'method_required'],
            'unknown product type' => [['product_type_id' => 999999], 'product_type_required'],
            'nothing selected' => [['variants' => [['vid' => 'v-1', 'selected' => false, 'cost_usd' => '3.47', 'shipping_cost_usd' => '5.43', 'price' => '14.99']]], 'no_variants'],
            'zero price' => [['variants' => [['vid' => 'v-1', 'selected' => true, 'cost_usd' => '3.47', 'shipping_cost_usd' => '5.43', 'price' => '0']]], 'price_required'],
            'no shipping' => [['variants' => [['vid' => 'v-1', 'selected' => true, 'cost_usd' => '3.47', 'shipping_cost_usd' => null, 'price' => '14.99']]], 'shipping_missing'],
            'no cost' => [['variants' => [['vid' => 'v-1', 'selected' => true, 'cost_usd' => null, 'shipping_cost_usd' => '5.43', 'price' => '14.99']]], 'cost_missing'],
            'negative margin' => [['variants' => [['vid' => 'v-1', 'selected' => true, 'cost_usd' => '3.47', 'shipping_cost_usd' => '5.43', 'price' => '5.00']]], 'negative_margin'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidListings')]
    public function test_rejects_invalid_listings(array $overrides, string $errorKey): void
    {
        $listing = Listing::fromArray([...$this->listing()->toArray(), ...$overrides]);

        try {
            app(ConfirmListing::class)->handle($this->candidate, $listing, false);
            $this->fail('Expected ListingException.');
        } catch (ListingException $exception) {
            $this->assertSame(__('lunar-cjdropshipping::admin.listing.errors.'.$errorKey, ['sku' => 'v-1']), $exception->getMessage());
        }

        $this->assertSame(CandidateStatus::Pending, $this->candidate->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_accepts_a_negative_margin_when_confirmed(): void
    {
        $listing = Listing::fromArray([...$this->listing()->toArray(), 'variants' => [
            ['vid' => 'v-1', 'selected' => true, 'cost_usd' => '3.47', 'shipping_cost_usd' => '5.43', 'price' => '5.00'],
        ]]);

        app(ConfirmListing::class)->handle($this->candidate, $listing, true);

        $this->assertSame(CandidateStatus::Approved, $this->candidate->fresh()->status);
    }

    public function test_only_pending_or_failed_items_can_be_confirmed(): void
    {
        $this->candidate->forceFill(['status' => CandidateStatus::Imported])->save();

        $this->expectException(ListingException::class);
        $this->expectExceptionMessage(__('lunar-cjdropshipping::admin.listing.errors.not_confirmable'));

        app(ConfirmListing::class)->handle($this->candidate, $this->listing(), false);
    }

    private function listing(): Listing
    {
        return new Listing(
            name: 'Plaid Dog Jacket',
            shipFromCountry: 'CN',
            shipToCountry: 'GB',
            currencyCode: 'GBP',
            shippingMethod: 'CJPacket Ordinary',
            markupPercent: '100',
            rounding: PriceRounding::Ends99,
            productTypeId: $this->productType->id,
            brandId: null,
            collectionId: null,
            variants: [
                new ListingVariant('v-1', true, '3.47', '5.43', '14.99'),
                new ListingVariant('v-2', false, '3.47', '5.43', null),
            ],
        );
    }
}
