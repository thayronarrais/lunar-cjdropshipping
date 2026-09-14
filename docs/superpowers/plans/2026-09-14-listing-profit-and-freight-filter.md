# Listing Profit, Warnings and Rule Freight Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the real profit per variant (net of VAT and card fee) with risk warnings on the listing confirmation page, and let import rules mark candidates whose shipping is too expensive.

**Architecture:**
- **VAT and pricing:** a `VatResolver` reads Lunar tax zones by destination country. `ListingPriceCalculator` gains `profit()` and a VAT- and fee-aware `margin()`, used by the confirm action, sync and the confirmation page.
- **Warnings:** the page adds a Profit column, per-row checks and a currency-mismatch banner. The banner resolves each site's currency through an optional `storefront_channels` lookup.
- **Freight filter:** rules gain destination, max shipping % and max quotes per run. A queued `CheckCandidateShippingJob`, dispatched after discovery, quotes one variant per candidate and marks `shipping_too_high`.

**Tech Stack:** PHP 8.2+, Laravel 11, Lunar 1.4, Filament 3.3 / Livewire 3, BCMath, PHPUnit 11, Orchestra Testbench 9, Larastan, Pint.

**Spec:** `packages/lunar-cjdropshipping/docs/specs/2026-09-14-listing-profit-and-freight-filter-design.md`

## Global Constraints

**Repo and workflow**
- Repo: `C:\laraenv\www\lunar\packages\lunar-cjdropshipping`. Start on `main` (v0.2.1 + spec commit) and create branch `feat/profit-freight-filter` in Task 1.
- Before committing, run the FULL suite `vendor/bin/phpunit` (unfiltered) and `vendor/bin/phpstan analyse --memory-limit=2G`. Both must be clean, and both summary lines go in the report.
- The working tree has unrelated line-ending-only changes. Never `git add -A`. Run Pint only on the files you changed, and stage by explicit path.
- Commit messages end with (after a blank line):
  ```
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw
  ```
- Never print `.env` values or API keys.

**Code conventions**
- Every PHP file uses `declare(strict_types=1);`, explicit types and PHPDoc array shapes. Classes are `final` unless Filament extends them.
- Money math uses BCMath with scale 12 (`private const SCALE = 12;`); never floats.
- Every new translation key goes into both `lang/en/admin.php` and `lang/pt_BR/admin.php`.
- Admin views use Filament Blade components. Tailwind utilities are limited to ones Filament core ships; use inline `style` for colours, e.g. `rgb(var(--danger-600))`.
- Larastan cannot see Livewire's magic `$this->form`; use `$this->getForm('form')?->...` as the existing pages do.
- In tests, `ChannelFactory` defaults to `default => true`, so always pass `default => false` for extra channels.

**Card fee config**
- `lunar-cjdropshipping.pricing.card_fee_percent` defaults to `1.5`.
- `lunar-cjdropshipping.pricing.card_fee_fixed` defaults to `0.20`, in listing-currency major units.

**Rulings taken while planning**
- VAT is returned as a small `Pricing\Vat` value object (`percent`, `inclusive`) instead of the spec's `array{percent, inclusive}`. Same data, typed.
- `SiteCurrencies` gives every channel without a `storefront_channels` row the Lunar default currency. The spec only named the default channel.
- A candidate with no `cost_usd` can't be compared, so it stays Pending once checked (shipping stored).

---

### Task 1: VAT resolver and profit-aware calculator

Start: `git checkout -b feat/profit-freight-filter`.

**Files:**
- Create: `src/Pricing/Vat.php`
- Create: `src/Pricing/VatResolver.php`
- Modify: `src/Pricing/ListingPriceCalculator.php`
- Modify: `src/LunarCjDropshippingServiceProvider.php` (scoped binding)
- Modify: `config/lunar-cjdropshipping.php`
- Create: `tests/Support/CreatesVatZone.php`
- Test: `tests/Feature/Pricing/VatResolverTest.php`
- Test: `tests/Feature/Pricing/ListingPriceCalculatorTest.php` (update margin expectations, add profit tests)

**Interfaces:**
- Produces:
  - `Pricing\Vat` (final readonly): `string $percent`, `bool $inclusive`, plus `static none(): self`, which is `new self('0', false)`.
  - `Pricing\VatResolver::forCountry(?string $iso2): Vat`, memoized per instance and bound `scoped` in the container.
  - `ListingPriceCalculator::profit(string $price, string $costUsd, string $shippingUsd, Currency $currency, ?Vat $vat = null): ?string`: major units with the currency's decimal places, half-up; `null` when price ≤ 0.
  - `ListingPriceCalculator::margin(string $price, string $costUsd, string $shippingUsd, Currency $currency, ?Vat $vat = null): ?string`: profit ÷ price × 100, 2 decimals; `null` when price ≤ 0.
  - Test helper trait `Tests\Support\CreatesVatZone::createVatZone(string $iso2 = 'GB', string $percent = '20', bool $inclusive = true): void`. It needs `CreatesLunarBaseline` already created, for `$this->taxClass`.
- Formulas (scale 12, round only at the end):
  - `vat = inclusive ? price × percent ÷ (100 + percent) : 0`
  - `fee = price × card_fee_percent ÷ 100 + card_fee_fixed`
  - `cost = (costUsd + shippingUsd) × PriceCalculator::usdToCurrencyRate(currency)`
  - `profit = price − vat − fee − cost`
  - `margin = profit ÷ price × 100`

Test baseline (`CreatesLunarBaseline`): EUR is the default; GBP has rate 0.85 and USD rate 1.08, so 1 USD = 0.787037037036 GBP.

Hand-computed values used below, for price 14.99, cost 3.47, shipping 5.43, GBP, fee 1.5% + 0.20:
- fee = 0.22485 + 0.20 = 0.42485
- cost = 8.90 × 0.787037037036 = 7.004629629620
- **No VAT:** profit = 7.560520370380 → **7.56**; margin = 50.437… → **50.44**
- **VAT 20% inclusive:** vat = 2.498333333333, so profit = 5.062187037047 → **5.06**; margin = 33.770… → **33.77**
- **Price 5.00, no VAT:** fee = 0.275, profit = −2.279629629620 → **−2.28**; margin = −45.5925… → **−45.59**

- [ ] **Step 1: Write the VAT zone test helper**

`tests/Support/CreatesVatZone.php`:

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Support;

use Lunar\Models\Country;
use Lunar\Models\TaxRate;
use Lunar\Models\TaxRateAmount;
use Lunar\Models\TaxZone;
use Lunar\Models\TaxZoneCountry;

/**
 * Requires CreatesLunarBaseline (already created) for $this->taxClass.
 */
trait CreatesVatZone
{
    protected function createVatZone(string $iso2 = 'GB', string $percent = '20', bool $inclusive = true): void
    {
        $country = Country::query()->where('iso2', $iso2)->first()
            ?? Country::factory()->create(['iso2' => $iso2, 'iso3' => $iso2.'X', 'name' => "Country {$iso2}"]);

        $zone = TaxZone::factory()->create([
            'name' => "{$iso2} VAT",
            'zone_type' => 'country',
            'price_display' => $inclusive ? 'tax_inclusive' : 'tax_exclusive',
            'active' => true,
            'default' => false,
        ]);

        TaxZoneCountry::factory()->create(['tax_zone_id' => $zone->id, 'country_id' => $country->id]);

        $rate = TaxRate::factory()->create(['tax_zone_id' => $zone->id, 'name' => 'VAT', 'priority' => 1]);

        TaxRateAmount::factory()->create(['tax_rate_id' => $rate->id, 'tax_class_id' => $this->taxClass->id, 'percentage' => $percent]);
    }
}
```

Note: if a Lunar factory needs extra attributes (for example `Country` phonecode, capital or currency), add them so the create succeeds, and record it in the report.

- [ ] **Step 2: Write the failing VAT resolver test**

`tests/Feature/Pricing/VatResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Pricing;

use Thayron\LunarCjDropshipping\Pricing\VatResolver;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesVatZone;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class VatResolverTest extends TestCase
{
    use CreatesLunarBaseline;
    use CreatesVatZone;

    public function test_returns_the_inclusive_rate_for_the_destination_country(): void
    {
        $this->createLunarBaseline();
        $this->createVatZone('GB', '20', inclusive: true);

        $vat = app(VatResolver::class)->forCountry('gb');

        $this->assertSame(0, bccomp($vat->percent, '20', 4));
        $this->assertTrue($vat->inclusive);
    }

    public function test_prefers_the_inclusive_zone_when_a_country_has_several(): void
    {
        $this->createLunarBaseline();
        $this->createVatZone('GB', '20', inclusive: false);
        $this->createVatZone('GB', '20', inclusive: true);

        $this->assertTrue(app(VatResolver::class)->forCountry('GB')->inclusive);
    }

    public function test_an_exclusive_zone_is_not_inclusive(): void
    {
        $this->createLunarBaseline();
        $this->createVatZone('GB', '20', inclusive: false);

        $this->assertFalse(app(VatResolver::class)->forCountry('GB')->inclusive);
    }

    public function test_returns_no_vat_for_an_unknown_or_empty_country(): void
    {
        $this->createLunarBaseline();

        $unknown = app(VatResolver::class)->forCountry('ZZ');
        $empty = app(VatResolver::class)->forCountry(null);

        $this->assertSame(['0', false], [$unknown->percent, $unknown->inclusive]);
        $this->assertSame(['0', false], [$empty->percent, $empty->inclusive]);
    }
}
```

- [ ] **Step 3: Update and extend the calculator test**

In `tests/Feature/Pricing/ListingPriceCalculatorTest.php`:
- Add `use Thayron\LunarCjDropshipping\Pricing\Vat;`.
- Replace `test_margin_is_a_percentage_of_the_price` with:

```php
    public function test_margin_is_profit_after_card_fee_as_a_percentage_of_the_price(): void
    {
        $this->assertSame('50.44', $this->calculator->margin('14.99', '3.47', '5.43', $this->gbp));
        $this->assertSame('-45.59', $this->calculator->margin('5.00', '3.47', '5.43', $this->gbp));
        $this->assertNull($this->calculator->margin('0', '3.47', '5.43', $this->gbp));
    }

    public function test_margin_deducts_inclusive_vat(): void
    {
        $this->assertSame('33.77', $this->calculator->margin('14.99', '3.47', '5.43', $this->gbp, new Vat('20', true)));
    }

    public function test_profit_deducts_vat_card_fee_and_converted_cost(): void
    {
        $this->assertSame('7.56', $this->calculator->profit('14.99', '3.47', '5.43', $this->gbp));
        $this->assertSame('5.06', $this->calculator->profit('14.99', '3.47', '5.43', $this->gbp, new Vat('20', true)));
        $this->assertSame('-2.28', $this->calculator->profit('5.00', '3.47', '5.43', $this->gbp));
        $this->assertNull($this->calculator->profit('0', '3.47', '5.43', $this->gbp));
    }

    public function test_exclusive_vat_is_not_deducted(): void
    {
        $this->assertSame('7.56', $this->calculator->profit('14.99', '3.47', '5.43', $this->gbp, new Vat('20', false)));
    }

    public function test_card_fee_comes_from_config(): void
    {
        config(['lunar-cjdropshipping.pricing.card_fee_percent' => 0, 'lunar-cjdropshipping.pricing.card_fee_fixed' => 0]);

        $this->assertSame('7.99', $this->calculator->profit('14.99', '3.47', '5.43', $this->gbp));
    }
```

(Without a fee: 14.99 − 7.004629629620 = 7.985370370380 → 7.99.)

- [ ] **Step 4: Run them and see them fail**

Run: `vendor/bin/phpunit tests/Feature/Pricing`
Expected: FAIL. `VatResolver`/`Vat` are not found; `profit()` is undefined; the margin values differ.

- [ ] **Step 5: Create `src/Pricing/Vat.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

/**
 * VAT applied to a sale price in one destination country.
 */
final readonly class Vat
{
    /**
     * @param  string  $percent  Rate in percent, e.g. "20".
     * @param  bool  $inclusive  Whether shop prices already include the VAT.
     */
    public function __construct(
        public string $percent,
        public bool $inclusive,
    ) {}

    public static function none(): self
    {
        return new self('0', false);
    }
}
```

- [ ] **Step 6: Create `src/Pricing/VatResolver.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

use Lunar\Models\TaxClass;
use Lunar\Models\TaxZone;

/**
 * VAT for a destination country, read from Lunar tax zones (default tax class). Memoized per instance.
 */
final class VatResolver
{
    /** @var array<string, Vat> */
    private array $resolved = [];

    public function forCountry(?string $iso2): Vat
    {
        $code = strtoupper(trim((string) $iso2));

        if ($code === '') {
            return Vat::none();
        }

        return $this->resolved[$code] ??= $this->resolve($code);
    }

    private function resolve(string $iso2): Vat
    {
        $taxClass = TaxClass::getDefault();

        if ($taxClass === null) {
            return Vat::none();
        }

        $zones = TaxZone::query()
            ->where('active', true)
            ->whereHas('countries.country', fn ($query) => $query->where('iso2', $iso2))
            ->with('taxRates.taxRateAmounts')
            ->orderBy('id')
            ->get();

        $zone = $zones->firstWhere('price_display', 'tax_inclusive') ?? $zones->first();

        if ($zone === null) {
            return Vat::none();
        }

        $percent = '0';

        foreach ($zone->taxRates as $rate) {
            foreach ($rate->taxRateAmounts as $amount) {
                if ((int) $amount->tax_class_id === (int) $taxClass->id) {
                    $percent = bcadd($percent, (string) $amount->percentage, 4);
                }
            }
        }

        return new Vat($percent, $zone->price_display === 'tax_inclusive');
    }
}
```

Note: if Larastan can't infer the relation types (`countries`, `taxRates`, `taxRateAmounts` exist on Lunar's models), add `@var` annotations; don't change behaviour.

- [ ] **Step 7: Bind it in `src/LunarCjDropshippingServiceProvider.php`**

In `register()`, after the `Throttle` singleton:

```php
        $this->app->scoped(Pricing\VatResolver::class);
```

- [ ] **Step 8: Add the card fee to the calculator**

In `src/Pricing/ListingPriceCalculator.php`, replace the `margin()` method with:

```php
    /**
     * Profit per unit in the listing currency after VAT (when prices include it), card fee and converted cost, or null when the price is not positive.
     */
    public function profit(string $price, string $costUsd, string $shippingUsd, Currency $currency, ?Vat $vat = null): ?string
    {
        $profit = $this->exactProfit($price, $costUsd, $shippingUsd, $currency, $vat);

        return $profit === null ? null : self::roundHalfUp($profit, (int) $currency->decimal_places);
    }

    /**
     * Profit as a percentage of the price, or null when the price is not positive.
     */
    public function margin(string $price, string $costUsd, string $shippingUsd, Currency $currency, ?Vat $vat = null): ?string
    {
        $profit = $this->exactProfit($price, $costUsd, $shippingUsd, $currency, $vat);

        if ($profit === null) {
            return null;
        }

        return self::roundHalfUp(bcmul(bcdiv($profit, $price, self::SCALE), '100', self::SCALE), 2);
    }
```

and add these private methods before `roundHalfUp()`:

```php
    private function exactProfit(string $price, string $costUsd, string $shippingUsd, Currency $currency, ?Vat $vat): ?string
    {
        if (bccomp($price, '0', self::SCALE) <= 0) {
            return null;
        }

        $vat ??= Vat::none();
        $vatAmount = $vat->inclusive
            ? bcdiv(bcmul($price, $vat->percent, self::SCALE), bcadd('100', $vat->percent, self::SCALE), self::SCALE)
            : '0';

        $fee = bcadd(bcdiv(bcmul($price, self::configNumber('card_fee_percent', '1.5'), self::SCALE), '100', self::SCALE), self::configNumber('card_fee_fixed', '0.20'), self::SCALE);
        $cost = bcmul(bcadd($costUsd, $shippingUsd, self::SCALE), $this->prices->usdToCurrencyRate($currency), self::SCALE);

        return bcsub(bcsub(bcsub($price, $vatAmount, self::SCALE), $fee, self::SCALE), $cost, self::SCALE);
    }

    private static function configNumber(string $key, string $default): string
    {
        $value = config("lunar-cjdropshipping.pricing.{$key}", $default);

        return is_numeric($value) ? (string) $value : $default;
    }
```

`Vat` lives in the same namespace, so no import is needed.

- [ ] **Step 9: Add the fee config**

In `config/lunar-cjdropshipping.php`, inside `'pricing'` after `min_margin_percent`:

```php
        // Card payment fee deducted when showing listing profit: percent of the price plus a fixed amount (listing currency).
        'card_fee_percent' => 1.5,
        'card_fee_fixed' => 0.20,
```

- [ ] **Step 10: Run the pricing tests, then the full suite and PHPStan**

Run: `vendor/bin/phpunit tests/Feature/Pricing` → PASS.
Then run `vendor/bin/phpunit` and `vendor/bin/phpstan analyse --memory-limit=2G`.
Expected: some existing tests may now fail because callers still use the old margin meaning. Only `tests/Feature/Listing/ConfirmListingTest.php` and `tests/Feature/Sync/SyncLockedPriceTest.php` may be affected; Task 2 updates them. If anything else fails, fix it in this task. If those two fail, list the failures in the report and continue; do not change their expectations here.

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint src/Pricing/Vat.php src/Pricing/VatResolver.php src/Pricing/ListingPriceCalculator.php src/LunarCjDropshippingServiceProvider.php config/lunar-cjdropshipping.php tests/Support/CreatesVatZone.php tests/Feature/Pricing/VatResolverTest.php tests/Feature/Pricing/ListingPriceCalculatorTest.php
git add src/Pricing/Vat.php src/Pricing/VatResolver.php src/Pricing/ListingPriceCalculator.php src/LunarCjDropshippingServiceProvider.php config/lunar-cjdropshipping.php tests/Support/CreatesVatZone.php tests/Feature/Pricing/VatResolverTest.php tests/Feature/Pricing/ListingPriceCalculatorTest.php
git commit -m "feat: VAT-aware listing profit and margin with card fee"
```

---

### Task 2: Confirm action and sync use VAT-aware margin

**Files:**
- Modify: `src/Actions/ConfirmListing.php`
- Modify: `src/Actions/SyncProduct.php`
- Test: `tests/Feature/Listing/ConfirmListingTest.php` (add one test)
- Test: `tests/Feature/Sync/SyncLockedPriceTest.php` (add two tests)

**Interfaces:**
- Consumes (from Task 1):
  - `VatResolver::forCountry(?string): Vat`
  - `ListingPriceCalculator::margin(string $price, string $costUsd, string $shippingUsd, Currency $currency, ?Vat $vat = null): ?string`
  - `Tests\Support\CreatesVatZone::createVatZone(string $iso2 = 'GB', string $percent = '20', bool $inclusive = true)`
- Produces (behaviour only):
  - The negative-margin check uses VAT from `Listing::$shipToCountry`.
  - Sync's `margin_at_risk` uses VAT from `ProductLink::$ship_to_country`.

Hand-computed values (test baseline: 1 USD = 0.787037037036 GBP; fee 1.5% + 0.20):
- **Confirm, price 8.00, cost 3.47 + shipping 5.43:**
  - No VAT: profit = 8 − 0.32 − 7.004629629620 = 0.675370… (positive)
  - GB VAT 20% inclusive: vat 1.333333…, profit = −0.657962… → **negative**
- **Sync, locked GBP price 30.00, v-1 cost 20.00 + stored shipping 5.00:**
  - cost = 25 × 0.787037037036 = 19.675925925900; fee = 0.65
  - No VAT: margin = 9.674074074100 ÷ 30 = **32.25%** (not at risk)
  - GB VAT 20% inclusive (vat 5.00): margin = 4.674074074100 ÷ 30 = **15.58%** → at risk (< 20)
  - v-2 (8.13 + 5.00) with VAT: margin = 46.72% (not at risk)

- [ ] **Step 1: Write the failing confirm test**

In `tests/Feature/Listing/ConfirmListingTest.php`, add `use Thayron\LunarCjDropshipping\Tests\Support\CreatesVatZone;`, then `use CreatesVatZone;` inside the class, then:

```php
    public function test_rejects_a_price_that_only_loses_money_after_vat(): void
    {
        $this->createVatZone('GB', '20', inclusive: true);
        $listing = Listing::fromArray([...$this->listing()->toArray(), 'variants' => [
            ['vid' => 'v-1', 'selected' => true, 'cost_usd' => '3.47', 'shipping_cost_usd' => '5.43', 'price' => '8.00'],
        ]]);

        try {
            app(ConfirmListing::class)->handle($this->candidate, $listing, false);
            $this->fail('Expected ListingException.');
        } catch (ListingException $exception) {
            $this->assertSame(__('lunar-cjdropshipping::admin.listing.errors.negative_margin'), $exception->getMessage());
        }

        $this->assertSame(CandidateStatus::Pending, $this->candidate->fresh()->status);
    }
```

(The `listing()` helper already ships to `GB` in `GBP`. `createLunarBaseline()` already runs in `setUp()`.)

- [ ] **Step 2: Write the failing sync tests**

In `tests/Feature/Sync/SyncLockedPriceTest.php`, add `use Thayron\LunarCjDropshipping\Tests\Support\CreatesVatZone;` and `use CreatesVatZone;`, then:

```php
    public function test_flags_margin_at_risk_when_destination_vat_eats_the_margin(): void
    {
        $this->createVatZone('GB', '20', inclusive: true);
        $this->link->forceFill(['ship_to_country' => 'GB'])->save();

        $this->syncWithCost('20.00');

        $this->assertTrue($this->link->fresh()->margin_at_risk);
    }

    public function test_the_same_cost_is_not_at_risk_without_a_destination(): void
    {
        $this->createVatZone('GB', '20', inclusive: true);

        $this->syncWithCost('20.00');

        $this->assertFalse($this->link->fresh()->margin_at_risk);
    }
```

- [ ] **Step 3: Run the tests and watch them fail**

Run: `vendor/bin/phpunit tests/Feature/Listing/ConfirmListingTest.php tests/Feature/Sync/SyncLockedPriceTest.php`
Expected: FAIL for both new VAT tests (no exception / not flagged). If other tests in these files fail because of Task 1's fee, record the actual versus expected numbers in the report and verify them by hand before touching expectations.

- [ ] **Step 4: Use VAT in `src/Actions/ConfirmListing.php`**

- Add `use Thayron\LunarCjDropshipping\Pricing\VatResolver;`.
- Change the constructor to:

```php
    public function __construct(
        private readonly ListingPriceCalculator $prices,
        private readonly VatResolver $vat,
    ) {}
```

- Right before `$negative = false;`, add `$vat = $this->vat->forCountry($listing->shipToCountry);`.
- Replace the margin line with:

```php
            $margin = $this->prices->margin($variant->price, $variant->costUsd, $variant->shippingCostUsd, $currency, $vat);
```

- [ ] **Step 5: Use VAT in `src/Actions/SyncProduct.php`**

- Add `use Thayron\LunarCjDropshipping\Pricing\Vat;` and `use Thayron\LunarCjDropshipping\Pricing\VatResolver;`.
- Add `private readonly VatResolver $vat,` as the last constructor parameter.
- Inside the transaction closure, after `$minimumMargin = ...;`, add:

```php
            $vat = $this->vat->forCountry($link->ship_to_country);
```

- Change the at-risk line to pass it:

```php
                    $marginAtRisk = $marginAtRisk || $this->isMarginAtRisk($variantLink, $cost ?? $variantLink->cost_usd, $currency, $minimumMargin, $vat);
```

- Change `isMarginAtRisk` to:

```php
    private function isMarginAtRisk(VariantLink $variantLink, ?string $costUsd, ?Currency $currency, string $minimumMargin, Vat $vat): bool
    {
        if ($currency === null || $costUsd === null || $variantLink->price === null || $variantLink->shipping_cost_usd === null) {
            return true;
        }

        $margin = $this->listingPrices->margin((string) $variantLink->price, $costUsd, (string) $variantLink->shipping_cost_usd, $currency, $vat);

        return $margin === null || bccomp($margin, $minimumMargin, 2) < 0;
    }
```

- Make sure the closure's `use (...)` list doesn't need `$vat`, since `$vat` is defined inside the closure.

- [ ] **Step 6: Run the tests, then the full suite and PHPStan**

Run: `vendor/bin/phpunit tests/Feature/Listing tests/Feature/Sync` → PASS.
Then run `vendor/bin/phpunit` and `vendor/bin/phpstan analyse --memory-limit=2G` → both clean.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint src/Actions/ConfirmListing.php src/Actions/SyncProduct.php tests/Feature/Listing/ConfirmListingTest.php tests/Feature/Sync/SyncLockedPriceTest.php
git add src/Actions/ConfirmListing.php src/Actions/SyncProduct.php tests/Feature/Listing/ConfirmListingTest.php tests/Feature/Sync/SyncLockedPriceTest.php
git commit -m "feat: confirm and sync use destination VAT in margin"
```

---

### Task 3: Confirmation page profit column, row checks and currency banner

**Files:**
- Create: `src/Support/SiteCurrencies.php`
- Modify: `src/Filament/Resources/CandidateResource/Pages/ConfirmListing.php`
- Modify: `resources/views/filament/pages/confirm-listing.blade.php`
- Modify: `lang/en/admin.php`, `lang/pt_BR/admin.php`
- Test: `tests/Filament/ConfirmListingPageTest.php` (add tests)

**Interfaces:**
- Consumes: `VatResolver::forCountry()`, `ListingPriceCalculator::profit()` / `margin(..., ?Vat $vat)` (Task 1).
- Produces:
  - `Support\SiteCurrencies::for(array $channelIds): array<int, string>` maps channel id to currency code. It reads `storefront_channels.currency_id` when that table exists; any other channel gets `Currency::getDefault()?->code`. Plain `final class`, resolved from the container.
  - Page methods (public, for the view):
    - `profitFor(int $index): ?string`
    - `checksFor(int $index): list<string>` returns keys from `rrp_below_cost` and `shipping_over_product`
    - `currencyMismatchSites(): list<string>` returns site names

Hand-computed page values:
- **v-1, GBP, GB VAT 20% inclusive:** price 24.99, cost 10.00 + shipping 5.43.
  - vat = 4.165; fee = 0.57485; cost = 15.43 × 0.787037037036 = 12.143981481465.
  - profit = 8.106168518535 → **8.11**; margin = **32.44**.
- **Checks:**
  - `shipping_over_product` when shipping USD > CJ cost USD (11.00 > 10.00).
  - `rrp_below_cost` when suggested USD < cost + shipping USD (15.00 < 21.00).

- [ ] **Step 1: Write the failing page tests**

In `tests/Filament/ConfirmListingPageTest.php`:
- Add the imports `use Illuminate\Database\Schema\Blueprint;`, `use Illuminate\Support\Facades\Schema;`, `use Lunar\Models\Channel;`, `use Lunar\Models\Currency;` and `use Thayron\LunarCjDropshipping\Tests\Support\CreatesVatZone;`.
- Add `use CreatesVatZone;` inside the class.
- Add these tests:

```php
    public function test_shows_profit_after_destination_vat_and_card_fee(): void
    {
        $this->createVatZone('GB', '20', inclusive: true);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');
        $page = Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()]);
        $this->cj
            ->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '5.43', 'logisticAging' => '7-12']])
            ->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '2.10', 'logisticAging' => '7-12']]);

        $page->fillForm(['ship_from_country' => 'CN', 'ship_to_country' => 'GB', 'currency_code' => 'GBP'])
            ->call('quoteShipping')
            ->set('variants.0.price', '24.99');

        $this->assertSame('8.11', $page->instance()->profitFor(0));
        $this->assertSame('32.44', $page->instance()->marginFor(0));
        $page->assertSee('8.11');
    }

    public function test_flags_variants_whose_shipping_or_rrp_make_them_risky(): void
    {
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');
        $page = Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()]);
        $this->cj
            ->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '11.00', 'logisticAging' => '7-12']])
            ->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '2.10', 'logisticAging' => '7-12']]);

        $page->fillForm(['ship_from_country' => 'CN', 'ship_to_country' => 'GB', 'currency_code' => 'GBP'])
            ->call('quoteShipping')
            ->set('variants.0.suggested_usd', '15.00');

        $this->assertSame(['rrp_below_cost', 'shipping_over_product'], $page->instance()->checksFor(0));
        $this->assertSame([], $page->instance()->checksFor(1));
        $page->assertSee(__('lunar-cjdropshipping::admin.listing.checks.shipping_over_product'));
    }

    public function test_warns_when_the_currency_differs_from_a_chosen_site(): void
    {
        Schema::create('storefront_channels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('currency_id')->nullable();
        });
        $tweed = Channel::factory()->create(['name' => 'Paws & Tweed', 'handle' => 'tweed', 'default' => false]);
        DB::table('storefront_channels')->insert(['channel_id' => $tweed->id, 'currency_id' => Currency::query()->where('code', 'GBP')->value('id')]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        $page = Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->fillForm(['currency_code' => 'EUR', 'channel_ids' => [$tweed->id]]);

        $this->assertSame(['Paws & Tweed'], $page->instance()->currencyMismatchSites());
        $page->assertSee(__('lunar-cjdropshipping::admin.listing.currency_mismatch', ['currency' => 'EUR', 'sites' => 'Paws & Tweed']));

        $page->fillForm(['currency_code' => 'GBP']);

        $this->assertSame([], $page->instance()->currencyMismatchSites());
    }

    public function test_no_currency_warning_for_the_default_site_in_the_default_currency(): void
    {
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        $page = Livewire::test(ConfirmListing::class, ['record' => $this->candidate->getRouteKey()])
            ->fillForm(['currency_code' => 'EUR', 'channel_ids' => [$this->channel->id]]);

        $this->assertSame([], $page->instance()->currencyMismatchSites());
    }
```

Add `use Illuminate\Support\Facades\DB;` if it isn't imported already. `$this->channel` is the baseline default channel (EUR is the default currency in the baseline). Changing `currency_code` clears prices (existing behaviour), which is why `fillForm` is called separately. If `fillForm` on `channel_ids` needs a list of strings, pass `[(string) $tweed->id]`.

- [ ] **Step 2: Run the tests and watch them fail**

Run: `vendor/bin/phpunit tests/Filament/ConfirmListingPageTest.php`
Expected: FAIL because `profitFor`, `checksFor`, `currencyMismatchSites` and the translations don't exist yet.

- [ ] **Step 3: Create `src/Support/SiteCurrencies.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lunar\Models\Currency;

/**
 * Currency each site (Lunar channel) sells in. Reads the optional `storefront_channels` table when present;
 * other channels use the Lunar default currency. Rebind in the container to customise.
 */
final class SiteCurrencies
{
    /**
     * @param  array<int, int>  $channelIds
     * @return array<int, string> channel id => currency code
     */
    public function for(array $channelIds): array
    {
        $default = Currency::getDefault()?->code;
        $codes = [];

        foreach ($channelIds as $channelId) {
            if ($default !== null) {
                $codes[(int) $channelId] = $default;
            }
        }

        if ($channelIds === [] || ! Schema::hasTable('storefront_channels')) {
            return $codes;
        }

        $currencies = (new Currency)->getTable();

        $rows = DB::table('storefront_channels')
            ->join($currencies, "{$currencies}.id", '=', 'storefront_channels.currency_id')
            ->whereIn('storefront_channels.channel_id', $channelIds)
            ->pluck("{$currencies}.code", 'storefront_channels.channel_id');

        foreach ($rows as $channelId => $code) {
            $codes[(int) $channelId] = (string) $code;
        }

        return $codes;
    }
}
```

- [ ] **Step 4: Add the page methods**

In `src/Filament/Resources/CandidateResource/Pages/ConfirmListing.php`:
- Add the imports `use Lunar\Models\Channel;` (skip if already imported), `use Thayron\LunarCjDropshipping\Pricing\Vat;`, `use Thayron\LunarCjDropshipping\Pricing\VatResolver;` and `use Thayron\LunarCjDropshipping\Support\SiteCurrencies;`.
- Change `marginFor()`'s last line to:

```php
        return app(ListingPriceCalculator::class)->margin((string) $row['price'], $row['cost_usd'], $shipping, $currency, $this->vat());
```

- Add after `marginFor()`:

```php
    public function profitFor(int $index): ?string
    {
        $row = $this->variants[$index] ?? null;
        $shipping = $this->shippingFor($index);
        $currency = $this->currency();

        if ($row === null || $row['cost_usd'] === null || $shipping === null || $currency === null || ! is_numeric($row['price'])) {
            return null;
        }

        return app(ListingPriceCalculator::class)->profit((string) $row['price'], $row['cost_usd'], $shipping, $currency, $this->vat());
    }

    /**
     * @return list<string>
     */
    public function checksFor(int $index): array
    {
        $row = $this->variants[$index] ?? null;
        $shipping = $this->shippingFor($index);
        $total = $this->totalFor($index);
        $checks = [];

        if ($row !== null && is_numeric($row['suggested_usd']) && $total !== null && bccomp((string) $row['suggested_usd'], $total, 2) < 0) {
            $checks[] = 'rrp_below_cost';
        }

        if ($row !== null && $row['cost_usd'] !== null && $shipping !== null && bccomp($shipping, $row['cost_usd'], 2) > 0) {
            $checks[] = 'shipping_over_product';
        }

        return $checks;
    }

    /**
     * Names of the chosen sites that sell in a different currency than the one selected.
     *
     * @return list<string>
     */
    public function currencyMismatchSites(): array
    {
        $code = (string) ($this->data['currency_code'] ?? '');
        $channelIds = array_values(array_unique(array_map('intval', (array) ($this->data['channel_ids'] ?? []))));

        if ($code === '' || $channelIds === []) {
            return [];
        }

        $mismatched = array_keys(array_filter(
            app(SiteCurrencies::class)->for($channelIds),
            fn (string $siteCurrency): bool => $siteCurrency !== $code,
        ));

        if ($mismatched === []) {
            return [];
        }

        return Channel::query()->whereIn('id', $mismatched)->orderBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->values()->all();
    }
```

- Add next to `currency()`:

```php
    private function vat(): Vat
    {
        return app(VatResolver::class)->forCountry((string) ($this->data['ship_to_country'] ?? ''));
    }
```

- [ ] **Step 5: Update the view `resources/views/filament/pages/confirm-listing.blade.php`**

Right before `<div style="overflow-x:auto" ...>` (the table wrapper), add:

```blade
            @if (($mismatchSites = $this->currencyMismatchSites()) !== [])
                <div class="rounded-lg p-3 text-sm" style="background-color:rgba(var(--warning-500),0.12);color:rgb(var(--warning-700))">
                    {{ __('lunar-cjdropshipping::admin.listing.currency_mismatch', ['currency' => $data['currency_code'] ?? '', 'sites' => implode(', ', $mismatchSites)]) }}
                </div>
            @endif
```

Change the header column list to `['selected', 'image', 'sku', 'variant', 'cost', 'shipping', 'total', 'rrp', 'price', 'profit', 'margin', 'checks']`.

In the row `@php` block, add:

```blade
                                $profit = $this->profitFor($index);
                                $checks = $this->checksFor($index);
```

Between the price `<td>` and the margin `<td>`, insert the profit cell:

```blade
                                <td class="px-3 py-2 font-medium" style="color:rgb(var(--{{ $profit === null ? 'gray' : (bccomp($profit, '0', 2) <= 0 ? 'danger' : ($belowMinimum ? 'warning' : 'success')) }}-600))">
                                    {{ $profit !== null ? $profit.' '.($data['currency_code'] ?? '') : '—' }}
                                </td>
```

After the margin `<td>`, add the checks cell:

```blade
                                <td class="px-3 py-2">
                                    @foreach ($checks as $check)
                                        <span title="{{ __('lunar-cjdropshipping::admin.listing.checks.'.$check) }}" style="color:rgb(var(--warning-600))">⚠️ {{ __('lunar-cjdropshipping::admin.listing.checks.'.$check) }}</span><br>
                                    @endforeach
                                </td>
```

Note: Livewire passes public properties to the view, so `$data` is available. If it isn't, use `$this->data`.

- [ ] **Step 6: Add the translations**

`lang/en/admin.php`, inside `listing`:
- Under `columns`, add `'profit' => 'Profit',` and `'checks' => 'Checks',`.
- Add these sibling keys:

```php
        'currency_mismatch' => 'The chosen currency (:currency) differs from the currency of: :sites.',
        'checks' => [
            'rrp_below_cost' => 'CJ RRP is below the total cost with shipping.',
            'shipping_over_product' => 'Shipping costs more than the product.',
        ],
```

`lang/pt_BR/admin.php`, inside `listing`:
- Under `columns`, add `'profit' => 'Lucro',` and `'checks' => 'Alertas',`.
- Add:

```php
        'currency_mismatch' => 'A moeda escolhida (:currency) é diferente da moeda de: :sites.',
        'checks' => [
            'rrp_below_cost' => 'O RRP da CJ é menor que o custo com frete.',
            'shipping_over_product' => 'O frete custa mais que o produto.',
        ],
```

- [ ] **Step 7: Run the page tests, then the full suite and PHPStan**

Run: `vendor/bin/phpunit tests/Filament/ConfirmListingPageTest.php` → PASS.
Then run `vendor/bin/phpunit` and `vendor/bin/phpstan analyse --memory-limit=2G` → both clean.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint src/Support/SiteCurrencies.php src/Filament/Resources/CandidateResource/Pages/ConfirmListing.php lang/en/admin.php lang/pt_BR/admin.php tests/Filament/ConfirmListingPageTest.php
git add src/Support/SiteCurrencies.php src/Filament/Resources/CandidateResource/Pages/ConfirmListing.php resources/views/filament/pages/confirm-listing.blade.php lang/en/admin.php lang/pt_BR/admin.php tests/Filament/ConfirmListingPageTest.php
git commit -m "feat: show listing profit, risk checks and site currency warning"
```

---

### Task 4: Rule freight filter schema and admin fields

**Files:**
- Create: `database/migrations/2026_09_15_000000_add_freight_filter_to_cj_tables.php`
- Modify: `src/Enums/CandidateStatus.php`
- Modify: `src/Models/ImportRule.php`, `src/Models/Candidate.php`
- Modify: `src/Filament/Resources/ImportRuleResource.php`
- Modify: `src/Filament/Resources/CandidateResource.php` (colour + reset filter)
- Modify: `lang/en/admin.php`, `lang/pt_BR/admin.php`
- Test: `tests/Filament/ImportRuleResourceTest.php` (add a test)
- Test: `tests/Filament/CandidateResourceTest.php` (add a test)

**Interfaces:**
- Produces:
  - Columns on `cj_import_rules`:
    - `ship_to_country` string(2) nullable
    - `max_shipping_percent` decimal(8,2) nullable
    - `max_quotes_per_run` unsigned smallint, default 50
  - Columns on `cj_candidates`: `shipping_usd` decimal(12,2) nullable, `shipping_checked_at` timestamp nullable.
  - `CandidateStatus::ShippingTooHigh = 'shipping_too_high'`.
  - `ImportRule::hasFreightFilter(): bool`, which is true when `ship_to_country` is filled and `max_shipping_percent !== null`.
  - Model properties and casts:
    - `ImportRule`: `max_shipping_percent` decimal:2, `max_quotes_per_run` integer, default attribute 50.
    - `Candidate`: `shipping_usd` decimal:2, `shipping_checked_at` datetime.

- [ ] **Step 1: Write the failing admin tests**

In `tests/Filament/ImportRuleResourceTest.php`, add a test that creates a rule through the create page with the new fields. Follow the file's existing create test for the page class, the `fillForm` keys, the category/country cache setup and the product type. It asserts the saved values:

```php
    public function test_saves_the_freight_filter(): void
    {
        Livewire::test(CreateImportRule::class)
            ->fillForm([
                'name' => 'Pets UK',
                'keyword' => 'dog',
                'markup_percent' => 100,
                'rounding' => 'none',
                'product_type_id' => $this->productType->id,
                'ship_to_country' => 'GB',
                'max_shipping_percent' => 100,
                'max_quotes_per_run' => 25,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = ImportRule::query()->where('name', 'Pets UK')->sole();
        $this->assertSame('GB', $rule->ship_to_country);
        $this->assertSame('100.00', $rule->max_shipping_percent);
        $this->assertSame(25, $rule->max_quotes_per_run);
        $this->assertTrue($rule->hasFreightFilter());
    }
```

Notes:
- `ship_to_country` options come from Lunar countries, so create `Country::factory()->create(['iso2' => 'GB', 'iso3' => 'GBR', 'name' => 'United Kingdom'])` first.
- Reuse whatever required fields and imports the existing create test uses (e.g. the `CreateImportRule` page class name). If the real class name differs, use the real one.

In `tests/Filament/CandidateResourceTest.php`, add:

```php
    public function test_bulk_reset_recovers_candidates_marked_shipping_too_high(): void
    {
        $candidate = $this->candidate('p-1', CandidateStatus::ShippingTooHigh);

        Livewire::test(ListCandidates::class)
            ->filterTable('status', 'shipping_too_high')
            ->callTableBulkAction('reset', [$candidate]);

        $this->assertSame(CandidateStatus::Pending, $candidate->fresh()->status);
    }
```

- [ ] **Step 2: Run the tests and watch them fail**

Run: `vendor/bin/phpunit tests/Filament/ImportRuleResourceTest.php tests/Filament/CandidateResourceTest.php`
Expected: FAIL (unknown enum case, missing fields/columns).

- [ ] **Step 3: Create the migration**

`database/migrations/2026_09_15_000000_add_freight_filter_to_cj_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cj_import_rules', function (Blueprint $table): void {
            $table->string('ship_to_country', 2)->nullable()->after('country_code');
            $table->decimal('max_shipping_percent', 8, 2)->nullable()->after('ship_to_country');
            $table->unsignedSmallInteger('max_quotes_per_run')->default(50)->after('max_shipping_percent');
        });

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->decimal('shipping_usd', 12, 2)->nullable()->after('cost_usd');
            $table->timestamp('shipping_checked_at')->nullable()->after('shipping_usd');
        });
    }

    public function down(): void
    {
        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->dropColumn(['shipping_usd', 'shipping_checked_at']);
        });

        Schema::table('cj_import_rules', function (Blueprint $table): void {
            $table->dropColumn(['ship_to_country', 'max_shipping_percent', 'max_quotes_per_run']);
        });
    }
};
```

- [ ] **Step 4: Update the enum and models**

In `src/Enums/CandidateStatus.php`, add `case ShippingTooHigh = 'shipping_too_high';` after `Unavailable`.

In `src/Models/ImportRule.php`:
- PHPDoc: add `@property string|null $ship_to_country`, `@property string|null $max_shipping_percent`, `@property int $max_quotes_per_run`.
- `$attributes`: add `'max_quotes_per_run' => 50,`.
- `casts()`: add `'max_shipping_percent' => 'decimal:2', 'max_quotes_per_run' => 'integer',`.
- Add the method:

```php
    public function hasFreightFilter(): bool
    {
        return filled($this->ship_to_country) && $this->max_shipping_percent !== null;
    }
```

In `src/Models/Candidate.php`:
- PHPDoc: add `@property string|null $shipping_usd` and `@property Carbon|null $shipping_checked_at`.
- `casts()`: add `'shipping_usd' => 'decimal:2', 'shipping_checked_at' => 'datetime',`.

- [ ] **Step 5: Add the rule form fields**

In `src/Filament/Resources/ImportRuleResource.php`, add `use Lunar\Models\Country;`, and after the `max_cost` field add:

```php
                Forms\Components\Select::make('ship_to_country')
                    ->label($field('ship_to_country'))
                    ->helperText($field('ship_to_country_help'))
                    ->searchable()
                    ->options(fn (): array => Country::query()->orderBy('name')->pluck('name', 'iso2')->all()),
                Forms\Components\TextInput::make('max_shipping_percent')
                    ->label($field('max_shipping_percent'))
                    ->helperText($field('max_shipping_percent_help'))
                    ->numeric()
                    ->minValue(0)
                    ->suffix('%'),
                Forms\Components\TextInput::make('max_quotes_per_run')
                    ->label($field('max_quotes_per_run'))
                    ->helperText($field('max_quotes_per_run_help'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(1000)
                    ->default(50)
                    ->required(),
```

- [ ] **Step 6: Update the import list resource**

In `src/Filament/Resources/CandidateResource.php`:
- In the status colour `match`, change `CandidateStatus::Ignored => 'warning',` to `CandidateStatus::Ignored, CandidateStatus::ShippingTooHigh => 'warning',`.
- In the `reset` bulk action filter list, add `CandidateStatus::ShippingTooHigh`.

- [ ] **Step 7: Add the translations**

`lang/en/admin.php`:
- Add `candidates.status.shipping_too_high` = `'Shipping too high'`.
- Add under `rules.fields`:

```php
            'ship_to_country' => 'Ship to (freight check)',
            'ship_to_country_help' => 'With a maximum shipping %, found products are quoted for this destination.',
            'max_shipping_percent' => 'Maximum shipping',
            'max_shipping_percent_help' => 'Shipping as a % of the CJ product cost. Products above it are marked "Shipping too high".',
            'max_quotes_per_run' => 'Max shipping quotes per run',
            'max_quotes_per_run_help' => 'Each quote costs 10 CJ quota points; the rest are checked on the next run.',
```

`lang/pt_BR/admin.php`:
- Add `candidates.status.shipping_too_high` = `'Frete alto'`.
- Add under `rules.fields`:

```php
            'ship_to_country' => 'Enviar para (checagem de frete)',
            'ship_to_country_help' => 'Com um frete máximo em %, os produtos encontrados são cotados para este destino.',
            'max_shipping_percent' => 'Frete máximo',
            'max_shipping_percent_help' => 'Frete em % do custo do produto na CJ. Produtos acima disso ficam como "Frete alto".',
            'max_quotes_per_run' => 'Máximo de cotações por execução',
            'max_quotes_per_run_help' => 'Cada cotação custa 10 pontos da cota da CJ; o restante é checado na próxima execução.',
```

- [ ] **Step 8: Run the tests, then the full suite and PHPStan**

Run: `vendor/bin/phpunit tests/Filament/ImportRuleResourceTest.php tests/Filament/CandidateResourceTest.php` → PASS.
Then run `vendor/bin/phpunit` and `vendor/bin/phpstan analyse --memory-limit=2G` → both clean.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint database/migrations/2026_09_15_000000_add_freight_filter_to_cj_tables.php src/Enums/CandidateStatus.php src/Models/ImportRule.php src/Models/Candidate.php src/Filament/Resources/ImportRuleResource.php src/Filament/Resources/CandidateResource.php lang/en/admin.php lang/pt_BR/admin.php tests/Filament/ImportRuleResourceTest.php tests/Filament/CandidateResourceTest.php
git add database/migrations/2026_09_15_000000_add_freight_filter_to_cj_tables.php src/Enums/CandidateStatus.php src/Models/ImportRule.php src/Models/Candidate.php src/Filament/Resources/ImportRuleResource.php src/Filament/Resources/CandidateResource.php lang/en/admin.php lang/pt_BR/admin.php tests/Filament/ImportRuleResourceTest.php tests/Filament/CandidateResourceTest.php
git commit -m "feat: freight filter fields on import rules and shipping-too-high status"
```

---

### Task 5: Candidate shipping check action and job

**Files:**
- Create: `src/Actions/CheckCandidateShipping.php`
- Create: `src/Jobs/CheckCandidateShippingJob.php`
- Modify: `src/Jobs/DiscoverCandidatesJob.php`
- Test: `tests/Feature/Discovery/CheckCandidateShippingTest.php`

**Interfaces:**
- Consumes (Task 4):
  - `ImportRule::hasFreightFilter()`, `ship_to_country`, `max_shipping_percent`, `max_quotes_per_run`
  - `Candidate::shipping_usd`, `shipping_checked_at`
  - `CandidateStatus::ShippingTooHigh`
- Consumes (existing):
  - `FreightQuoter::quote(string $productId, string $from, string $to, array $weightsByVid): array<array-key, array<string, FreightOption>>`
  - `Throttle::wait()`, `QuotaDelay::seconds()`, `CjLog::channel()`
- Produces:
  - `CheckCandidateShipping::handle(ImportRule $rule): array{checked: int, too_high: int, skipped: int}`. It rethrows `QuotaExceededException`.
  - `Jobs\CheckCandidateShippingJob(ImportRule $rule)`, unique per rule.
  - `DiscoverCandidatesJob` dispatches it after discovery when `hasFreightFilter()` is true.

Behaviour:
1. Select the rule's candidates with status Pending and `shipping_checked_at` null, ordered by id, limited to `max_quotes_per_run`.
2. For each candidate:
   - `find()` the product (throttled).
   - Pick the lightest variant (by numeric `weight`).
   - Origin is `rule.country_code` uppercased; otherwise the first warehouse country with stock from `inventoryByProduct()` (throttled); otherwise `CN`.
   - Quote that one variant to `rule.ship_to_country`, and take the cheapest `priceUsd` rounded to 2 decimals.
3. Status:
   - `ShippingTooHigh` when no option is quoted, or when cost is known and `shipping > cost × max_shipping_percent ÷ 100`.
   - Otherwise it stays Pending.
   - Store `shipping_usd` and `shipping_checked_at`.
4. Errors:
   - `NotFoundException` → `Unavailable` with `shipping_checked_at` set (skipped).
   - Any other non-quota exception → log a warning, set `shipping_checked_at` with a null shipping, keep Pending (skipped).
   - `QuotaExceededException` propagates.

Fixtures:
- `product-detail`: v-1 weighs 1580 g and costs 10.00; v-2 weighs 120 g and costs 8.13.
- `stock-by-pid`: product-level inventory in US with 7 units.
- FakeCj error codes: 1602001 is NotFound, 1600201 is QuotaExceeded.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Discovery/CheckCandidateShippingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Discovery;

use Illuminate\Support\Facades\Queue;
use Thayron\CjDropshipping\Exceptions\QuotaExceededException;
use Thayron\LunarCjDropshipping\Actions\CheckCandidateShipping;
use Thayron\LunarCjDropshipping\Actions\DiscoverCandidates;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Jobs\CheckCandidateShippingJob;
use Thayron\LunarCjDropshipping\Jobs\DiscoverCandidatesJob;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class CheckCandidateShippingTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cjdropshipping.max_retries' => 0]);
        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
    }

    public function test_marks_a_candidate_whose_cheapest_shipping_exceeds_the_limit(): void
    {
        $rule = $this->rule(['country_code' => 'CN']);
        $candidate = $this->candidate($rule, 'p-100', '10.00');
        $this->cj->fixture('product-detail')->success([
            ['logisticName' => 'Yun Express', 'logisticPrice' => '15.00', 'logisticAging' => '8-12'],
            ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '12.00', 'logisticAging' => '7-12'],
        ]);

        $stats = app(CheckCandidateShipping::class)->handle($rule);

        $this->assertSame(['checked' => 1, 'too_high' => 1, 'skipped' => 0], $stats);
        $this->assertSame(['product/query', 'logistic/freightCalculate'], $this->cj->paths());
        $this->assertSame(['startCountryCode' => 'CN', 'endCountryCode' => 'GB', 'products' => [['vid' => 'v-2', 'quantity' => 1]]], $this->cj->jsonAt(1));
        $candidate->refresh();
        $this->assertSame(CandidateStatus::ShippingTooHigh, $candidate->status);
        $this->assertSame('12.00', $candidate->shipping_usd);
        $this->assertNotNull($candidate->shipping_checked_at);
    }

    public function test_keeps_a_candidate_pending_when_shipping_is_within_the_limit(): void
    {
        $rule = $this->rule(['country_code' => 'CN']);
        $candidate = $this->candidate($rule, 'p-100', '10.00');
        $this->cj->fixture('product-detail')->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '4.00', 'logisticAging' => '7-12']]);

        app(CheckCandidateShipping::class)->handle($rule);

        $candidate->refresh();
        $this->assertSame(CandidateStatus::Pending, $candidate->status);
        $this->assertSame('4.00', $candidate->shipping_usd);
        $this->assertNotNull($candidate->shipping_checked_at);
    }

    public function test_marks_shipping_too_high_when_no_method_is_quoted(): void
    {
        $rule = $this->rule(['country_code' => 'CN']);
        $candidate = $this->candidate($rule, 'p-100', '10.00');
        $this->cj->fixture('product-detail')->success([]);

        app(CheckCandidateShipping::class)->handle($rule);

        $this->assertSame(CandidateStatus::ShippingTooHigh, $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->shipping_usd);
    }

    public function test_uses_the_first_warehouse_with_stock_when_the_rule_has_no_origin(): void
    {
        $rule = $this->rule(['country_code' => null]);
        $this->candidate($rule, 'p-100', '10.00');
        $this->cj->fixture('product-detail')->fixture('stock-by-pid')->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '4.00', 'logisticAging' => '7-12']]);

        app(CheckCandidateShipping::class)->handle($rule);

        $this->assertSame('US', $this->cj->jsonAt(2)['startCountryCode']);
    }

    public function test_checks_at_most_the_configured_number_of_candidates_per_run(): void
    {
        $rule = $this->rule(['country_code' => 'CN', 'max_quotes_per_run' => 1]);
        $first = $this->candidate($rule, 'p-100', '10.00');
        $second = $this->candidate($rule, 'p-200', '10.00');
        $this->cj->fixture('product-detail')->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => '4.00', 'logisticAging' => '7-12']]);

        app(CheckCandidateShipping::class)->handle($rule);

        $this->assertNotNull($first->fresh()->shipping_checked_at);
        $this->assertNull($second->fresh()->shipping_checked_at);
    }

    public function test_skips_candidates_already_checked(): void
    {
        $rule = $this->rule(['country_code' => 'CN']);
        $candidate = $this->candidate($rule, 'p-100', '10.00');
        $candidate->forceFill(['shipping_checked_at' => now()])->save();

        $stats = app(CheckCandidateShipping::class)->handle($rule);

        $this->assertSame(['checked' => 0, 'too_high' => 0, 'skipped' => 0], $stats);
        $this->assertSame([], $this->cj->requests());
    }

    public function test_marks_products_removed_from_cj_as_unavailable(): void
    {
        $rule = $this->rule(['country_code' => 'CN']);
        $candidate = $this->candidate($rule, 'p-100', '10.00');
        $this->cj->error(1602001, 'Product not found');

        $stats = app(CheckCandidateShipping::class)->handle($rule);

        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(CandidateStatus::Unavailable, $candidate->fresh()->status);
    }

    public function test_stops_when_the_cj_quota_is_used_up(): void
    {
        $rule = $this->rule(['country_code' => 'CN']);
        $candidate = $this->candidate($rule, 'p-100', '10.00');
        $this->cj->error(1600201, 'Quota exceeded');

        try {
            app(CheckCandidateShipping::class)->handle($rule);
            $this->fail('Expected QuotaExceededException.');
        } catch (QuotaExceededException) {
            $this->assertNull($candidate->fresh()->shipping_checked_at);
        }
    }

    public function test_the_job_releases_itself_until_quota_resets(): void
    {
        $rule = $this->rule(['country_code' => 'CN']);
        $this->candidate($rule, 'p-100', '10.00');
        $this->cj->error(1600201, 'Quota exceeded');
        $job = (new CheckCandidateShippingJob($rule))->withFakeQueueInteractions();

        $job->handle(app(CheckCandidateShipping::class));

        $job->assertReleased();
    }

    public function test_discovery_queues_the_check_only_for_rules_with_a_freight_filter(): void
    {
        Queue::fake([CheckCandidateShippingJob::class]);
        $withFilter = $this->rule(['keyword' => 'case']);
        $withoutFilter = $this->rule(['keyword' => 'case', 'ship_to_country' => null, 'max_shipping_percent' => null]);
        $this->cj->fixture('list-v2-page')->fixture('list-v2-page');

        (new DiscoverCandidatesJob($withFilter))->handle(app(DiscoverCandidates::class));
        (new DiscoverCandidatesJob($withoutFilter))->handle(app(DiscoverCandidates::class));

        Queue::assertPushed(CheckCandidateShippingJob::class, fn (CheckCandidateShippingJob $job): bool => $job->rule->is($withFilter));
        Queue::assertPushed(CheckCandidateShippingJob::class, 1);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rule(array $attributes = []): ImportRule
    {
        return ImportRule::create([
            'name' => 'Rule '.uniqid(),
            'keyword' => 'case',
            'markup_percent' => '100',
            'rounding' => PriceRounding::None,
            'product_type_id' => $this->productType->id,
            'ship_to_country' => 'GB',
            'max_shipping_percent' => '100',
            'max_quotes_per_run' => 50,
            ...$attributes,
        ]);
    }

    private function candidate(ImportRule $rule, string $cjProductId, ?string $costUsd): Candidate
    {
        return Candidate::create([
            'import_rule_id' => $rule->id,
            'cj_product_id' => $cjProductId,
            'name' => 'Product '.$cjProductId,
            'cost_usd' => $costUsd,
            'status' => CandidateStatus::Pending,
            'payload' => [],
            'discovered_at' => now(),
        ]);
    }
}
```

Notes:
- `uniqid()` keeps rule names distinct and is not asserted. If the discovery test creates a candidate `p-100` twice across the two rules, that's fine: discovery upserts by product.
- If `withFakeQueueInteractions()` or `assertReleased()` aren't available in the installed Laravel 11 version, assert instead that the handle call doesn't throw and that `$job->job` recorded a release. Report what you used.

- [ ] **Step 2: Run the test and watch it fail**

Run: `vendor/bin/phpunit tests/Feature/Discovery/CheckCandidateShippingTest.php`
Expected: FAIL because the classes don't exist.

- [ ] **Step 3: Create `src/Actions/CheckCandidateShipping.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Data\FreightOption;
use Thayron\CjDropshipping\Data\Variant as CjVariant;
use Thayron\CjDropshipping\Exceptions\NotFoundException;
use Thayron\CjDropshipping\Exceptions\QuotaExceededException;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Logistics\FreightQuoter;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Support\CjLog;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Throwable;

/**
 * Quotes shipping for a rule's new candidates and marks the ones whose shipping is too expensive.
 * Each candidate costs one product call and one freight quote (10 CJ quota points).
 */
final class CheckCandidateShipping
{
    private const SCALE = 12;

    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
        private readonly FreightQuoter $quoter,
    ) {}

    /**
     * @return array{checked: int, too_high: int, skipped: int}
     *
     * @throws QuotaExceededException
     */
    public function handle(ImportRule $rule): array
    {
        $stats = ['checked' => 0, 'too_high' => 0, 'skipped' => 0];

        if (! $rule->hasFreightFilter()) {
            return $stats;
        }

        $candidates = $rule->candidates()
            ->where('status', CandidateStatus::Pending->value)
            ->whereNull('shipping_checked_at')
            ->orderBy('id')
            ->limit(max(1, $rule->max_quotes_per_run))
            ->get();

        foreach ($candidates as $candidate) {
            try {
                $shipping = $this->cheapestShipping($rule, $candidate);
            } catch (QuotaExceededException $exception) {
                throw $exception;
            } catch (NotFoundException) {
                $candidate->forceFill(['status' => CandidateStatus::Unavailable, 'shipping_checked_at' => now()])->save();
                $stats['skipped']++;

                continue;
            } catch (Throwable $exception) {
                CjLog::channel()->warning('CJ shipping check failed', ['cj_product_id' => $candidate->cj_product_id, 'message' => $exception->getMessage()]);
                $candidate->forceFill(['shipping_usd' => null, 'shipping_checked_at' => now()])->save();
                $stats['skipped']++;

                continue;
            }

            $tooHigh = $shipping === null || $this->exceedsLimit($rule, $candidate, $shipping);

            $candidate->forceFill([
                'shipping_usd' => $shipping,
                'shipping_checked_at' => now(),
                'status' => $tooHigh ? CandidateStatus::ShippingTooHigh : CandidateStatus::Pending,
            ])->save();

            $stats['checked']++;
            $stats['too_high'] += $tooHigh ? 1 : 0;
        }

        return $stats;
    }

    private function cheapestShipping(ImportRule $rule, Candidate $candidate): ?string
    {
        $this->throttle->wait();
        $product = $this->cj->products()->find($candidate->cj_product_id);

        /** @var CjVariant|null $lightest */
        $lightest = collect($product->variants)
            ->sortBy(fn (CjVariant $variant): float => is_numeric($variant->weight) ? (float) $variant->weight : PHP_FLOAT_MAX)
            ->first();

        if ($lightest === null) {
            return null;
        }

        $from = filled($rule->country_code) ? strtoupper((string) $rule->country_code) : $this->originFor($candidate);
        $quote = $this->quoter->quote($product->id, $from, strtoupper((string) $rule->ship_to_country), [$lightest->id => $lightest->weight]);

        $prices = array_values(array_filter(
            array_map(fn (FreightOption $option): ?string => $option->priceUsd, $quote[$lightest->id] ?? []),
            fn (?string $price): bool => is_numeric($price),
        ));

        if ($prices === []) {
            return null;
        }

        usort($prices, fn (string $a, string $b): int => bccomp($a, $b, 4));

        return bcadd($prices[0], '0', 2);
    }

    private function originFor(Candidate $candidate): string
    {
        $this->throttle->wait();
        $inventory = $this->cj->products()->inventoryByProduct($candidate->cj_product_id);

        foreach ($inventory->warehouses as $stock) {
            if ($stock->countryCode !== null && $stock->total > 0) {
                return strtoupper($stock->countryCode);
            }
        }

        return 'CN';
    }

    private function exceedsLimit(ImportRule $rule, Candidate $candidate, string $shipping): bool
    {
        if ($candidate->cost_usd === null) {
            return false;
        }

        $limit = bcdiv(bcmul((string) $candidate->cost_usd, (string) $rule->max_shipping_percent, self::SCALE), '100', self::SCALE);

        return bccomp($shipping, $limit, 2) > 0;
    }
}
```

Note: the `catch (QuotaExceededException $exception) { throw $exception; }` block must come before `Throwable`. Keep it even if PHPStan calls it redundant; if PHPStan fails on it, restructure so quota exceptions still propagate.

- [ ] **Step 4: Create `src/Jobs/CheckCandidateShippingJob.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Thayron\CjDropshipping\Exceptions\QuotaExceededException;
use Thayron\LunarCjDropshipping\Actions\CheckCandidateShipping;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Support\QuotaDelay;

final class CheckCandidateShippingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $maxExceptions = 3;

    public int $timeout = 3600;

    public int $uniqueFor = 90000;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public ImportRule $rule)
    {
        $this->onQueue((string) config('lunar-cjdropshipping.queue'));
    }

    public function uniqueId(): string
    {
        return 'cj-shipping-check-'.$this->rule->id;
    }

    /**
     * Quota releases do not consume attempts; only real exceptions are capped by $maxExceptions.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(48);
    }

    public function handle(CheckCandidateShipping $check): void
    {
        try {
            $check->handle($this->rule);
        } catch (QuotaExceededException) {
            $this->release(QuotaDelay::seconds());
        }
    }
}
```

- [ ] **Step 5: Dispatch it after discovery**

In `src/Jobs/DiscoverCandidatesJob.php`, change `handle()` to:

```php
    public function handle(DiscoverCandidates $discover): void
    {
        try {
            $discover->handle($this->rule);
        } catch (QuotaExceededException) {
            $this->release(QuotaDelay::seconds());

            return;
        }

        if ($this->rule->hasFreightFilter()) {
            CheckCandidateShippingJob::dispatch($this->rule);
        }
    }
```

- [ ] **Step 6: Run the test, then the full suite and PHPStan**

Run: `vendor/bin/phpunit tests/Feature/Discovery/CheckCandidateShippingTest.php` → PASS.
Then run `vendor/bin/phpunit` and `vendor/bin/phpstan analyse --memory-limit=2G` → both clean.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint src/Actions/CheckCandidateShipping.php src/Jobs/CheckCandidateShippingJob.php src/Jobs/DiscoverCandidatesJob.php tests/Feature/Discovery/CheckCandidateShippingTest.php
git add src/Actions/CheckCandidateShipping.php src/Jobs/CheckCandidateShippingJob.php src/Jobs/DiscoverCandidatesJob.php tests/Feature/Discovery/CheckCandidateShippingTest.php
git commit -m "feat: check candidate shipping after discovery and mark shipping too high"
```

---

### Task 6: Release importer v0.3.0 and update the Lunar app

Controller step. Publishing to the user's own GitHub repo is pre-authorized. The Lunar app repo is never committed.

- [ ] **Step 1:** In the importer repo on `feat/profit-freight-filter`, run the full `vendor/bin/phpunit` and `vendor/bin/phpstan analyse --memory-limit=2G`. Both must be clean.
- [ ] **Step 2:** Release without checkout noise:
  `git branch -f main HEAD && git checkout -q main && git branch -d feat/profit-freight-filter && git tag v0.3.0 && git push origin main v0.3.0`
- [ ] **Step 3:** Back up the app DB before migrating. Use `/c/laraenv/mysql/bin/mysqldump.exe` with credentials read from Laravel config inside the command, never echoed, writing to `storage/app/backups/pre-cj-freight-<timestamp>.sql`.
- [ ] **Step 4:** In `C:\laraenv\www\lunar`, run `php artisan migrate --no-interaction`, `php artisan optimize:clear` and `php artisan queue:restart`. Then relaunch the worker `php artisan queue:work --queue=cjdropshipping,default --timeout=3600 --tries=0 --sleep=3 --memory=256` in the background.
- [ ] **Step 5:** Smoke check: `php artisan tinker --execute 'echo json_encode(Illuminate\Support\Facades\Schema::hasColumns("cj_import_rules",["ship_to_country","max_shipping_percent","max_quotes_per_run"]));'` prints `true`.
