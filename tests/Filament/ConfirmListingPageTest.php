<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Country;
use Thayron\LunarCjDropshipping\Enums\CandidateSource;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource;
use Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource\Pages\ConfirmListing;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Tests\FilamentTestCase;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;

final class ConfirmListingPageTest extends FilamentTestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createLunarBaseline();
        $this->actingAsStaff();
        Country::factory()->create(['name' => 'United Kingdom', 'iso2' => 'GB', 'iso3' => 'GBR']);
        Country::factory()->create(['name' => 'China', 'iso2' => 'CN', 'iso3' => 'CHN']);
        $this->cj = FakeCj::install($this->app);
        $this->candidate = Candidate::create([
            'cj_product_id' => 'p-100', 'source' => CandidateSource::Catalog, 'name' => 'Magnetic Phone Case',
            'status' => CandidateStatus::Pending, 'payload' => [], 'discovered_at' => now(),
        ]);
    }

    public function test_quotes_shipping_recommends_prices_and_lists_the_product(): void
    {
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        $page = Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->assertSet('variants.0.vid', 'v-1')
            ->assertSet('variants.0.cost_usd', '10.00')
            ->assertSet('variants.1.cost_usd', '8.13')
            ->assertFormSet(['name' => 'Magnetic Phone Case', 'currency_code' => 'EUR']);

        $this->cj
            ->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '5.43', 'logisticAging' => '7-12']])
            ->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '2.10', 'logisticAging' => '7-12']]);

        $page
            ->fillForm([
                'name' => 'Magnetic Case',
                'ship_from_country' => 'CN',
                'ship_to_country' => 'GB',
                'currency_code' => 'GBP',
                'markup_percent' => '100',
                'rounding' => 'ends_99',
            ])
            ->call('quoteShipping')
            ->assertFormSet(['shipping_method' => 'CJPacket Ordinary'])
            ->assertSet('variants.0.methods.CJPacket Ordinary.price_usd', '5.43')
            ->call('recommendPrices')
            ->assertSet('variants.0.price', '24.99')
            ->assertSet('variants.1.price', '16.99')
            ->set('variants.1.selected', false)
            ->call('listNow')
            ->assertHasNoFormErrors()
            ->assertRedirect(CandidateResource::getUrl('index'));

        $candidate = $this->candidate->fresh();
        $this->assertSame(CandidateStatus::Approved, $candidate->status);
        $this->assertSame('Magnetic Case', $candidate->listing['name']);
        $this->assertSame('GB', $candidate->listing['ship_to_country']);
        $this->assertSame(['vid' => 'v-1', 'selected' => true, 'cost_usd' => '10.00', 'shipping_cost_usd' => '5.43', 'price' => '24.99'], $candidate->listing['variants'][0]);
        $this->assertFalse($candidate->listing['variants'][1]['selected']);
        Queue::assertPushed(ImportProductJob::class);
    }

    public function test_changing_currency_clears_typed_prices(): void
    {
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->fillForm(['currency_code' => 'GBP'])
            ->set('variants.0.price', '24.99')
            ->set('variants.1.price', '16.99')
            ->set('data.currency_code', 'EUR')
            ->assertSet('variants.0.price', null)
            ->assertSet('variants.1.price', null)
            ->assertNotified(__('lunar-cjdropshipping::admin.listing.prices_cleared'));
    }

    public function test_bulk_adjusts_selected_prices(): void
    {
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->fillForm(['currency_code' => 'GBP'])
            ->set('variants.0.price', '20.00')
            ->set('variants.1.price', '10.00')
            ->set('variants.1.selected', false)
            ->set('bulkMode', 'percent')
            ->set('bulkValue', '10')
            ->call('applyBulk')
            ->assertSet('variants.0.price', '22.00')
            ->assertSet('variants.1.price', '10.00')
            ->set('bulkMode', 'amount')
            ->set('bulkValue', '-2.5')
            ->call('applyBulk')
            ->assertSet('variants.0.price', '19.50')
            ->set('bulkMode', 'set')
            ->set('bulkValue', '15')
            ->call('applyBulk')
            ->assertSet('variants.0.price', '15.00');
    }

    public function test_shows_validation_errors_without_losing_prices(): void
    {
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->fillForm(['ship_from_country' => 'CN', 'ship_to_country' => 'GB', 'currency_code' => 'GBP'])
            ->set('variants.0.price', '20.00')
            ->call('listNow')
            ->assertNotified(__('lunar-cjdropshipping::admin.listing.errors.method_required'))
            ->assertSet('variants.0.price', '20.00');

        $this->assertSame(CandidateStatus::Pending, $this->candidate->fresh()->status);
    }

    public function test_marks_products_removed_from_cj_as_unavailable(): void
    {
        $this->cj->error(1602001, 'Product not found');

        Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->assertSee(__('lunar-cjdropshipping::admin.listing.unavailable'));

        $this->assertSame(CandidateStatus::Unavailable, $this->candidate->fresh()->status);
    }

    public function test_shipping_method_options_are_limited_to_methods_common_to_selected_variants(): void
    {
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        $page = Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->fillForm(['ship_from_country' => 'CN', 'ship_to_country' => 'GB', 'currency_code' => 'GBP']);

        $this->cj
            ->success([
                ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '5.43', 'logisticAging' => '7-12'],
                ['logisticName' => 'USPS+', 'logisticPrice' => '6.00', 'logisticAging' => '5-9'],
            ])
            ->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '2.10', 'logisticAging' => '7-12']]);

        $page->call('quoteShipping');

        $options = $page->instance()->methodOptions();
        $this->assertSame(['CJPacket Ordinary'], array_keys($options));
        $this->assertStringContainsString('2.10–5.43', $options['CJPacket Ordinary']);
        $page->assertFormSet(['shipping_method' => 'CJPacket Ordinary']);

        $page->set('variants.1.selected', false);

        $optionsAfterDeselecting = $page->instance()->methodOptions();
        $this->assertContains('USPS+', array_keys($optionsAfterDeselecting));

        $page
            ->set('variants.0.price', '20.00')
            ->set('data.shipping_method', 'USPS+')
            ->call('listNow')
            ->assertHasNoFormErrors();

        $this->assertSame(CandidateStatus::Approved, $this->candidate->fresh()->status);
    }

    public function test_blocks_opening_the_page_for_a_candidate_that_is_not_pending_or_failed(): void
    {
        $this->candidate->forceFill(['status' => CandidateStatus::Approved])->save();

        Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->assertSee(__('lunar-cjdropshipping::admin.listing.errors.not_confirmable'));

        $this->assertSame([], $this->cj->requests());
        $this->assertSame(CandidateStatus::Approved, $this->candidate->fresh()->status);
    }

    public function test_forbids_a_staff_user_without_the_permission_before_any_cj_call(): void
    {
        // A CJ "not found" response would otherwise mark the candidate Unavailable as a
        // mount() side effect before the 403 is thrown (see finding F2).
        $this->cj->error(1602001, 'Product not found');

        $staff = Staff::factory()->create(['admin' => false]);
        $this->actingAs($staff, 'staff');
        Filament::setCurrentPanel(Filament::getPanel('lunar'));

        Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->assertForbidden();

        $this->assertCount(0, $this->cj->requests());
        $this->assertSame(CandidateStatus::Pending, $this->candidate->fresh()->status);
    }
}
