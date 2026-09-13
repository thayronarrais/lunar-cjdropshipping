# CJ Catalog & Listing Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Browse the CJ catalog inside the Lunar admin, add products to an import list, and confirm each one by hand (name, ship from/to, currency, shipping method, per-variant prices with a recommendation) before importing it with locked prices.

**Architecture:** The SDK gains suggested sell prices and a `logistics()->freightCalculate()` resource (v0.2.0). The importer reuses `cj_candidates` as the import list, adds a Filament catalog page and a per-item confirmation page, stores the confirmed listing as JSON, imports only selected variants with the confirmed prices, and makes sync keep locked prices while flagging `margin_at_risk`.

**Tech Stack:** PHP 8.2+, Laravel 11, Lunar 1.4, Filament 3.3 / Livewire 3, BCMath, PHPUnit 11, Orchestra Testbench 9, Larastan, Pint, Guzzle MockHandler.

**Spec:** `packages/lunar-cjdropshipping/docs/specs/2026-09-13-cj-catalog-listing-design.md`

## Global Constraints

- Repos: SDK at `C:\laraenv\www\lunar\packages\cjdropshipping-php` (Tasks 1–2), importer at `C:\laraenv\www\lunar\packages\lunar-cjdropshipping` (Tasks 3–12). Run every command from that repo's root.
- Every PHP file: `declare(strict_types=1);`, explicit return types, PHPDoc array shapes, curly braces always, `final` classes unless extended by Filament.
- Money math uses BCMath with scale 12 (`private const SCALE = 12;`); never floats.
- No new composer dependencies. The only dependency change is the importer requiring `"thayron/cjdropshipping-php": "^0.2"`.
- Never print the CJ API key or `.env` values.
- Tests: SDK `vendor/bin/phpunit` + `vendor/bin/phpstan analyse` (level 8, clean). Importer `vendor/bin/phpunit` + `vendor/bin/phpstan analyse --memory-limit=2G` (clean).
- Pint only the files you touched: `vendor/bin/pint path/one.php path/two.php` (the working tree has unrelated CRLF-only changes — never run Pint on the whole repo, never `git add -A`; stage task files by path).
- Admin views: use Filament Blade components (`x-filament-panels::page`, `x-filament::button`, `x-filament::badge`, `x-filament::section`, `x-filament::input.*`). Tailwind utilities are limited to ones Filament core already ships; use inline `style` for grid templates, aspect ratio and line clamping.
- Every new translation key goes into both `lang/en/admin.php` and `lang/pt_BR/admin.php`.
- Commit messages end with:
  ```
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw
  ```
- Rulings recorded while planning:
  - `cj_product_links.skipped_cj_variant_ids` (json) stores unselected variants so sync does not report them as new (spec amended).
  - `CandidateStatus::Approved` means "listing confirmed, import job dispatched" (no new `queued` status).
  - No live freight test (each call costs 10 CJ quota points); mocked tests only.
  - Importing *new* variants into a price-locked link (`ImportNewVariants`) keeps today's automatic pricing — out of scope.

---

### Task 1: SDK suggested sell prices

Repo: `packages/cjdropshipping-php`. Start a branch: `git checkout -b feat/freight-calculation`.

**Files:**
- Modify: `src/Data/Variant.php`
- Modify: `src/Data/Product.php`
- Test: `tests/Unit/Data/SuggestedSellPriceTest.php`

**Interfaces:**
- Produces: `Variant::$suggestedSellPrice` (`?string`, USD, from `variantSugSellPrice`), `Product::$suggestedSellPrice` (`?string`, USD, may be a range, from `suggestSellPrice`). Both are the LAST constructor parameter (after `$raw`) with default `null`, so existing positional constructions keep working.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thayron\CjDropshipping\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use Thayron\CjDropshipping\Data\Product;
use Thayron\CjDropshipping\Data\Variant;

final class SuggestedSellPriceTest extends TestCase
{
    public function test_maps_variant_suggested_sell_price(): void
    {
        $variant = Variant::fromArray(['vid' => 'v-1', 'variantSellPrice' => '3.47', 'variantSugSellPrice' => 27.51]);

        $this->assertSame('27.51', $variant->suggestedSellPrice);
    }

    public function test_maps_product_suggested_sell_price_range(): void
    {
        $product = Product::fromArray(['pid' => 'p-1', 'suggestSellPrice' => '0.97-4.08']);

        $this->assertSame('0.97-4.08', $product->suggestedSellPrice);
    }

    public function test_suggested_prices_default_to_null(): void
    {
        $this->assertNull(Variant::fromArray(['vid' => 'v-1'])->suggestedSellPrice);
        $this->assertNull(Product::fromArray(['pid' => 'p-1'])->suggestedSellPrice);
    }
}
```

- [ ] **Step 2: Run it and see it fail**

Run: `vendor/bin/phpunit tests/Unit/Data/SuggestedSellPriceTest.php`
Expected: FAIL — `Undefined property ... $suggestedSellPrice`.

- [ ] **Step 3: Implement**

In `src/Data/Variant.php` add the PHPDoc line `@param  string|null  $suggestedSellPrice  CJ suggested retail price, USD.`, add `public ?string $suggestedSellPrice = null,` after `private array $raw = [],`, and in `fromArray()` add as the last argument after `$data,`:

```php
            Arr::string($data, 'variantSugSellPrice'),
```

In `src/Data/Product.php` add the PHPDoc line `@param  string|null  $suggestedSellPrice  CJ suggested retail price, USD; may be a range like "0.97-4.08".`, add `public ?string $suggestedSellPrice = null,` after `private array $raw = [],`, and in `fromArray()` add after `$data,`:

```php
            Arr::string($data, 'suggestSellPrice'),
```

- [ ] **Step 4: Run tests and analysis**

Run: `vendor/bin/phpunit` then `vendor/bin/phpstan analyse`
Expected: all green, no PHPStan errors.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src/Data/Variant.php src/Data/Product.php tests/Unit/Data/SuggestedSellPriceTest.php
git add src/Data/Variant.php src/Data/Product.php tests/Unit/Data/SuggestedSellPriceTest.php
git commit -m "feat: expose CJ suggested sell prices on products and variants"
```

---

### Task 2: SDK freight calculation + release v0.2.0

Repo: `packages/cjdropshipping-php`, branch `feat/freight-calculation`.

**Files:**
- Create: `src/Criteria/FreightQuery.php`
- Create: `src/Data/FreightOption.php`
- Create: `src/Resources/LogisticsResource.php`
- Modify: `src/CjClient.php`
- Test: `tests/Unit/Criteria/FreightQueryTest.php`
- Test: `tests/Unit/Resources/LogisticsResourceTest.php`

**Interfaces:**
- Produces:
  - `FreightQuery::make(string $fromCountryCode, string $toCountryCode): FreightQuery` (codes upper-cased, must be 2 letters, else `InvalidArgumentException`)
  - `FreightQuery::product(string $variantId, int $quantity = 1): FreightQuery` (immutable)
  - `FreightQuery::toPayload(): array{startCountryCode: string, endCountryCode: string, products: list<array{vid: string, quantity: int}>}` (throws `InvalidArgumentException` when no products)
  - `FreightOption` readonly: `string $name`, `?string $priceUsd`, `?string $priceCny`, `?string $aging`, `raw(): array`, `static fromArray(array): self`, `static collect(array): list<self>`
  - `LogisticsResource::freightCalculate(FreightQuery $query): list<FreightOption>` → `POST logistic/freightCalculate`
  - `CjClient::logistics(): LogisticsResource`

- [ ] **Step 1: Write the failing criteria test**

```php
<?php

declare(strict_types=1);

namespace Thayron\CjDropshipping\Tests\Unit\Criteria;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Thayron\CjDropshipping\Criteria\FreightQuery;

final class FreightQueryTest extends TestCase
{
    public function test_builds_the_payload(): void
    {
        $query = FreightQuery::make('cn', 'gb')->product('v-1')->product('v-2', 3);

        $this->assertSame([
            'startCountryCode' => 'CN',
            'endCountryCode' => 'GB',
            'products' => [['vid' => 'v-1', 'quantity' => 1], ['vid' => 'v-2', 'quantity' => 3]],
        ], $query->toPayload());
    }

    public function test_is_immutable(): void
    {
        $base = FreightQuery::make('CN', 'GB');
        $base->product('v-1');

        $this->expectException(InvalidArgumentException::class);
        $base->toPayload();
    }

    public function test_rejects_invalid_country_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FreightQuery::make('China', 'GB');
    }

    public function test_rejects_non_positive_quantities(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FreightQuery::make('CN', 'GB')->product('v-1', 0);
    }
}
```

- [ ] **Step 2: Write the failing resource test**

```php
<?php

declare(strict_types=1);

namespace Thayron\CjDropshipping\Tests\Unit\Resources;

use Thayron\CjDropshipping\Criteria\FreightQuery;
use Thayron\CjDropshipping\Resources\LogisticsResource;
use Thayron\CjDropshipping\Tests\TestCase;

final class LogisticsResourceTest extends TestCase
{
    private LogisticsResource $logistics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeValidToken();
        $this->logistics = new LogisticsResource($this->connector());
    }

    public function test_calculates_freight_options(): void
    {
        $this->http->queueSuccess([
            ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 5.43, 'logisticPriceCN' => 38.1, 'logisticAging' => '7-12'],
            ['logisticName' => 'USPS+', 'logisticPrice' => '9.10', 'logisticPriceCN' => '63.70', 'logisticAging' => '4-8'],
        ]);

        $options = $this->logistics->freightCalculate(FreightQuery::make('CN', 'GB')->product('v-1'));

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api2.0/v1/logistic/freightCalculate', $request->getUri()->getPath());
        $this->assertSame(['startCountryCode' => 'CN', 'endCountryCode' => 'GB', 'products' => [['vid' => 'v-1', 'quantity' => 1]]], self::jsonBodyOf($request));

        $this->assertCount(2, $options);
        $this->assertSame('CJPacket Ordinary', $options[0]->name);
        $this->assertSame('5.43', $options[0]->priceUsd);
        $this->assertSame('38.1', $options[0]->priceCny);
        $this->assertSame('7-12', $options[0]->aging);
        $this->assertSame('USPS+', $options[1]->name);
        $this->assertSame('9.10', $options[1]->raw()['logisticPrice']);
    }

    public function test_skips_options_without_a_name(): void
    {
        $this->http->queueSuccess([['logisticPrice' => 1.0], ['logisticName' => 'Yun Express', 'logisticPrice' => 3]]);

        $options = $this->logistics->freightCalculate(FreightQuery::make('CN', 'US')->product('v-1'));

        $this->assertCount(1, $options);
        $this->assertSame('3', $options[0]->priceUsd);
    }

    public function test_client_exposes_the_logistics_resource(): void
    {
        $this->assertInstanceOf(LogisticsResource::class, $this->client()->logistics());
        $this->assertSame($this->client()->logistics(), $this->client()->logistics());
    }
}
```

Note: `client()` may build a new `CjClient` on each call; if the last assertion fails for that reason, store `$client = $this->client();` and compare `$client->logistics()` twice.

- [ ] **Step 3: Run and see both fail**

Run: `vendor/bin/phpunit tests/Unit/Criteria/FreightQueryTest.php tests/Unit/Resources/LogisticsResourceTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 4: Implement `src/Criteria/FreightQuery.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\CjDropshipping\Criteria;

use InvalidArgumentException;

/**
 * Criteria for logistic/freightCalculate.
 */
final readonly class FreightQuery
{
    /**
     * @param  list<array{vid: string, quantity: int}>  $products
     */
    private function __construct(
        public string $fromCountryCode,
        public string $toCountryCode,
        public array $products = [],
    ) {}

    public static function make(string $fromCountryCode, string $toCountryCode): self
    {
        return new self(self::countryCode($fromCountryCode), self::countryCode($toCountryCode));
    }

    public function product(string $variantId, int $quantity = 1): self
    {
        if ($variantId === '') {
            throw new InvalidArgumentException('The variant id cannot be empty.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException(sprintf('The quantity must be at least 1, %d given.', $quantity));
        }

        return new self($this->fromCountryCode, $this->toCountryCode, [...$this->products, ['vid' => $variantId, 'quantity' => $quantity]]);
    }

    /**
     * @return array{startCountryCode: string, endCountryCode: string, products: list<array{vid: string, quantity: int}>}
     */
    public function toPayload(): array
    {
        if ($this->products === []) {
            throw new InvalidArgumentException('Add at least one product to the freight query.');
        }

        return [
            'startCountryCode' => $this->fromCountryCode,
            'endCountryCode' => $this->toCountryCode,
            'products' => $this->products,
        ];
    }

    private static function countryCode(string $code): string
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new InvalidArgumentException(sprintf('Country codes must have two letters, "%s" given.', $code));
        }

        return $code;
    }
}
```

- [ ] **Step 5: Implement `src/Data/FreightOption.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\CjDropshipping\Data;

use Thayron\CjDropshipping\Support\Arr;

final readonly class FreightOption
{
    /**
     * @param  string  $name  CJ logistic name, used as the shipping method when ordering.
     * @param  string|null  $priceUsd  Shipping cost, USD.
     * @param  string|null  $priceCny  Shipping cost, CNY.
     * @param  string|null  $aging  Delivery time in days, e.g. "7-12".
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public string $name,
        public ?string $priceUsd,
        public ?string $priceCny,
        public ?string $aging,
        private array $raw = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data  One item of logistic/freightCalculate `data`.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::requiredString($data, 'logisticName'),
            Arr::string($data, 'logisticPrice'),
            Arr::string($data, 'logisticPriceCN'),
            Arr::string($data, 'logisticAging'),
            $data,
        );
    }

    /**
     * Options without a logistic name are skipped.
     *
     * @param  array<array-key, mixed>  $records
     * @return list<self>
     */
    public static function collect(array $records): array
    {
        $options = [];

        foreach ($records as $record) {
            if (is_array($record) && Arr::string($record, 'logisticName') !== null && Arr::string($record, 'logisticName') !== '') {
                $options[] = self::fromArray($record);
            }
        }

        return $options;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
```

- [ ] **Step 6: Implement `src/Resources/LogisticsResource.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\CjDropshipping\Resources;

use Thayron\CjDropshipping\Criteria\FreightQuery;
use Thayron\CjDropshipping\Data\FreightOption;
use Thayron\CjDropshipping\Http\Connector;
use Thayron\CjDropshipping\Support\Arr;

final class LogisticsResource
{
    public function __construct(private readonly Connector $connector) {}

    /**
     * Shipping methods and costs for the given variants. Costs 10 CJ quota points per call.
     *
     * @return list<FreightOption>
     */
    public function freightCalculate(FreightQuery $query): array
    {
        $payload = $query->toPayload();

        return FreightOption::collect(Arr::ensureArray($this->connector->post('logistic/freightCalculate', $payload), 'freight calculation'));
    }
}
```

- [ ] **Step 7: Expose it on `src/CjClient.php`**

Add `use Thayron\CjDropshipping\Resources\LogisticsResource;`, the property `private ?LogisticsResource $logistics = null;` next to `$webhooks`, and after `webhooks()`:

```php
    public function logistics(): LogisticsResource
    {
        return $this->logistics ??= new LogisticsResource($this->connector);
    }
```

- [ ] **Step 8: Run tests and analysis**

Run: `vendor/bin/phpunit` then `vendor/bin/phpstan analyse`
Expected: all green, no PHPStan errors.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint src/Criteria/FreightQuery.php src/Data/FreightOption.php src/Resources/LogisticsResource.php src/CjClient.php tests/Unit/Criteria/FreightQueryTest.php tests/Unit/Resources/LogisticsResourceTest.php
git add src/Criteria/FreightQuery.php src/Data/FreightOption.php src/Resources/LogisticsResource.php src/CjClient.php tests/Unit/Criteria/FreightQueryTest.php tests/Unit/Resources/LogisticsResourceTest.php
git commit -m "feat: add logistics freight calculation"
```

- [ ] **Step 10: Release v0.2.0** (controller step — publishing to the user's own GitHub repo is pre-authorized)

```bash
git checkout main
git merge --ff-only feat/freight-calculation
vendor/bin/phpunit
git tag v0.2.0
git push origin main v0.2.0
git branch -d feat/freight-calculation
```

Then confirm Packagist sees it: `composer show thayron/cjdropshipping-php --all` (run from the importer repo) must list `v0.2.0`. If it does not within ~5 minutes, stop and ask the user to press "Update" on the Packagist package page.

---

### Task 3: Importer schema, models, enums and config

Repo: `packages/lunar-cjdropshipping`. Start a branch: `git checkout -b feat/catalog-listing`.

**Files:**
- Modify: `composer.json` (`"thayron/cjdropshipping-php": "^0.2"`)
- Create: `database/migrations/2026_09_14_000000_add_listing_columns_to_cj_tables.php`
- Create: `src/Enums/CandidateSource.php`
- Modify: `src/Enums/CandidateStatus.php`
- Modify: `src/Models/Candidate.php`, `src/Models/ProductLink.php`, `src/Models/VariantLink.php`
- Modify: `config/lunar-cjdropshipping.php`
- Modify: `src/Filament/Resources/CandidateResource.php` (null-safe rule + `Unavailable` colour only)
- Modify: `src/Actions/ImportProduct.php` (guard against a missing rule only)
- Modify: `lang/en/admin.php`, `lang/pt_BR/admin.php`
- Test: `tests/Feature/Models/ListingColumnsTest.php`

**Interfaces:**
- Produces:
  - `enum CandidateSource: string { case Catalog = 'catalog'; case Rule = 'rule'; }`
  - `CandidateStatus::Unavailable = 'unavailable'`
  - `Candidate`: `import_rule_id` `int|null`, `source` `CandidateSource`, `listing` `array<string, mixed>|null` (cast `array`), `listed_at` `Carbon|null`, relation `importRule` may be null; unique `cj_product_id`.
  - `ProductLink`: `ship_from_country`, `ship_to_country` (`string|null`), `shipping_method` (`string|null`), `currency_code` (`string|null`), `price_locked` (`bool`), `margin_at_risk` (`bool`), `skipped_cj_variant_ids` (`list<string>`, cast `array`, default `[]`).
  - `VariantLink`: `shipping_cost_usd` (`string|null`, `decimal:2`), `price` (`string|null`, `decimal:2`, major units of the link currency).
  - Config: `lunar-cjdropshipping.pricing.min_margin_percent` (default `20`), `lunar-cjdropshipping.freight.cache_ttl` (default `21600`).

- [ ] **Step 1: Require SDK ^0.2**

In `composer.json` change `"thayron/cjdropshipping-php": "^0.1"` to `"thayron/cjdropshipping-php": "^0.2"`, then run:

```bash
composer update thayron/cjdropshipping-php --with-dependencies
```

Expected: `thayron/cjdropshipping-php` upgraded to `v0.2.0`. Verify: `composer show thayron/cjdropshipping-php | grep versions`.

- [ ] **Step 2: Write the failing test**

`tests/Feature/Models/ListingColumnsTest.php`:

```php
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
```

Note: if `$product->variants()->create([...])` fails because Lunar requires more variant columns, use `\Lunar\Models\ProductVariant::factory()->create(['product_id' => $product->id])->id` instead.

- [ ] **Step 3: Run and see it fail**

Run: `vendor/bin/phpunit tests/Feature/Models/ListingColumnsTest.php`
Expected: FAIL — `Class "...CandidateSource" not found` / missing columns.

- [ ] **Step 4: Create the enum `src/Enums/CandidateSource.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Enums;

enum CandidateSource: string
{
    case Catalog = 'catalog';
    case Rule = 'rule';
}
```

Add to `src/Enums/CandidateStatus.php` after `case Failed = 'failed';`:

```php
    case Unavailable = 'unavailable';
```

- [ ] **Step 5: Create the migration**

`database/migrations/2026_09_14_000000_add_listing_columns_to_cj_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->removeDuplicateCandidates();

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->index('import_rule_id');
        });

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->dropUnique(['import_rule_id', 'cj_product_id']);
            $table->unique('cj_product_id');
            $table->foreignId('import_rule_id')->nullable()->change();
            $table->string('source')->default('rule')->after('import_rule_id');
            $table->json('listing')->nullable()->after('payload');
            $table->timestamp('listed_at')->nullable()->after('listing');
        });

        Schema::table('cj_product_links', function (Blueprint $table): void {
            $table->string('ship_from_country', 2)->nullable()->after('country_code');
            $table->string('ship_to_country', 2)->nullable()->after('ship_from_country');
            $table->string('shipping_method')->nullable()->after('ship_to_country');
            $table->string('currency_code', 3)->nullable()->after('shipping_method');
            $table->boolean('price_locked')->default(false)->after('currency_code');
            $table->boolean('margin_at_risk')->default(false)->index()->after('price_locked');
            $table->json('skipped_cj_variant_ids')->nullable()->after('new_cj_variant_ids');
        });

        Schema::table('cj_variant_links', function (Blueprint $table): void {
            $table->decimal('shipping_cost_usd', 12, 2)->nullable()->after('cost_usd');
            $table->decimal('price', 12, 2)->nullable()->after('shipping_cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('cj_variant_links', function (Blueprint $table): void {
            $table->dropColumn(['shipping_cost_usd', 'price']);
        });

        Schema::table('cj_product_links', function (Blueprint $table): void {
            $table->dropIndex(['margin_at_risk']);
            $table->dropColumn(['ship_from_country', 'ship_to_country', 'shipping_method', 'currency_code', 'price_locked', 'margin_at_risk', 'skipped_cj_variant_ids']);
        });

        DB::table('cj_candidates')->whereNull('import_rule_id')->delete();

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->dropUnique(['cj_product_id']);
            $table->dropColumn(['source', 'listing', 'listed_at']);
            $table->foreignId('import_rule_id')->nullable(false)->change();
            $table->unique(['import_rule_id', 'cj_product_id']);
        });

        Schema::table('cj_candidates', function (Blueprint $table): void {
            $table->dropIndex(['import_rule_id']);
        });
    }

    /**
     * The same CJ product found by several rules becomes one list item: keep the imported row, else the oldest.
     */
    private function removeDuplicateCandidates(): void
    {
        $duplicates = DB::table('cj_candidates')
            ->select('cj_product_id')
            ->groupBy('cj_product_id')
            ->havingRaw('count(*) > 1')
            ->pluck('cj_product_id');

        foreach ($duplicates as $cjProductId) {
            $keep = DB::table('cj_candidates')
                ->where('cj_product_id', $cjProductId)
                ->orderByRaw("case when status = 'imported' then 0 else 1 end")
                ->orderBy('id')
                ->value('id');

            DB::table('cj_candidates')->where('cj_product_id', $cjProductId)->where('id', '!=', $keep)->delete();
        }
    }
};
```

- [ ] **Step 6: Update the models**

`src/Models/Candidate.php`:
- PHPDoc: `@property int|null $import_rule_id`, add `@property CandidateSource $source`, `@property array<string, mixed>|null $listing`, `@property Carbon|null $listed_at`, change `@property-read ImportRule $importRule` to `@property-read ImportRule|null $importRule`.
- `use Thayron\LunarCjDropshipping\Enums\CandidateSource;`
- Add `protected $attributes = ['source' => 'rule'];`
- Add to `casts()`: `'source' => CandidateSource::class, 'listing' => 'array', 'listed_at' => 'datetime',`

`src/Models/ProductLink.php`:
- PHPDoc: `@property string|null $ship_from_country`, `@property string|null $ship_to_country`, `@property string|null $shipping_method`, `@property string|null $currency_code`, `@property bool $price_locked`, `@property bool $margin_at_risk`, `@property list<string> $skipped_cj_variant_ids`.
- `$attributes`: add `'price_locked' => false, 'margin_at_risk' => false, 'skipped_cj_variant_ids' => '[]',`
- `casts()`: add `'price_locked' => 'boolean', 'margin_at_risk' => 'boolean', 'skipped_cj_variant_ids' => 'array',`
- Existing rows have `skipped_cj_variant_ids = null`; every reader uses `$link->skipped_cj_variant_ids ?? []`.

`src/Models/VariantLink.php`:
- PHPDoc: `@property string|null $shipping_cost_usd`, `@property string|null $price`.
- `casts()`: add `'shipping_cost_usd' => 'decimal:2', 'price' => 'decimal:2',`

- [ ] **Step 7: Config**

In `config/lunar-cjdropshipping.php`, inside `'pricing'` after `usd_to_default_rate`:

```php
        // Locked listing prices whose margin falls below this percentage are flagged "margin at risk" on sync.
        'min_margin_percent' => 20,
```

and after the `'pricing'` block:

```php
    'freight' => [
        // Seconds CJ shipping quotes are cached per product, origin and destination.
        'cache_ttl' => 21600,
    ],
```

- [ ] **Step 8: Keep existing code compiling with a nullable rule**

`src/Filament/Resources/CandidateResource.php`:
- In the `sale_price` column state, change the condition to `$record->cost_usd === null || $record->importRule === null ? null : PricePreview::amounts(...)`.
- In the status colour `match`, change `CandidateStatus::Failed => 'danger',` to `CandidateStatus::Failed, CandidateStatus::Unavailable => 'danger',`.

`src/Actions/ImportProduct.php`, in `handle()` replace
`$link = DB::transaction(fn (): ProductLink => $this->createProduct($candidate->importRule, $cjProduct, $inventory));`
with:

```php
        $rule = $candidate->importRule ?? throw new ImportException("Candidate {$candidate->cj_product_id} has no import rule.");
        $link = DB::transaction(fn (): ProductLink => $this->createProduct($rule, $cjProduct, $inventory));
```

(Task 10 replaces this with the listing path.)

- [ ] **Step 9: Translations**

`lang/en/admin.php` → in `candidates.status` add `'unavailable' => 'Unavailable on CJ',`; in `candidates` add:

```php
        'source' => [
            'catalog' => 'Catalog',
            'rule' => 'Rule',
        ],
```

`lang/pt_BR/admin.php` → in `candidates.status` add `'unavailable' => 'Indisponível na CJ',`; in `candidates` add:

```php
        'source' => [
            'catalog' => 'Catálogo',
            'rule' => 'Regra',
        ],
```

- [ ] **Step 10: Run the full suite and analysis**

Run: `vendor/bin/phpunit` then `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: all green (existing tests unchanged), no Larastan errors.

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint database/migrations/2026_09_14_000000_add_listing_columns_to_cj_tables.php src/Enums/CandidateSource.php src/Enums/CandidateStatus.php src/Models/Candidate.php src/Models/ProductLink.php src/Models/VariantLink.php config/lunar-cjdropshipping.php src/Filament/Resources/CandidateResource.php src/Actions/ImportProduct.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Models/ListingColumnsTest.php
git add composer.json composer.lock database/migrations/2026_09_14_000000_add_listing_columns_to_cj_tables.php src/Enums/CandidateSource.php src/Enums/CandidateStatus.php src/Models/Candidate.php src/Models/ProductLink.php src/Models/VariantLink.php config/lunar-cjdropshipping.php src/Filament/Resources/CandidateResource.php src/Actions/ImportProduct.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Models/ListingColumnsTest.php
git commit -m "feat: add listing columns to import list and product links"
```

(If `composer.lock` is not tracked in this repo, drop it from `git add`.)

---

### Task 4: Listing price calculator

**Files:**
- Modify: `src/Pricing/PriceCalculator.php`
- Create: `src/Pricing/ListingPriceCalculator.php`
- Test: `tests/Feature/Pricing/ListingPriceCalculatorTest.php`

**Interfaces:**
- Consumes: `PriceCalculator::priceFor(string $costUsd, string $markupPercent, PriceRounding $rounding, Currency $currency, ?string $usdRate = null): int` (minor units), `PriceCalculator::usdRate(): string`.
- Produces:
  - `PriceCalculator::usdToCurrencyRate(Currency $currency): string` — value of 1 USD in `$currency`.
  - `ListingPriceCalculator::recommend(string $costUsd, string $shippingUsd, string $markupPercent, PriceRounding $rounding, Currency $currency): string` — major units with the currency's decimal places, e.g. `"24.99"`.
  - `ListingPriceCalculator::margin(string $price, string $costUsd, string $shippingUsd, Currency $currency): ?string` — percent of the price, 2 decimals, e.g. `"53.27"`; `null` when price ≤ 0.
  - `ListingPriceCalculator::inCurrency(string $usd, Currency $currency): string` — USD converted, rounded half-up to the currency's decimals.
  - `ListingPriceCalculator::roundHalfUp(string $value, int $decimals): string` (static).

Baseline used by tests (`CreatesLunarBaseline`): EUR default (rate 1), GBP rate 0.85, USD rate 1.08 → 1 USD = 0.925925925925 EUR, 0.787037037036 GBP.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Pricing;

use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Pricing\ListingPriceCalculator;
use Thayron\LunarCjDropshipping\Pricing\PriceCalculator;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ListingPriceCalculatorTest extends TestCase
{
    use CreatesLunarBaseline;

    private ListingPriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->calculator = app(ListingPriceCalculator::class);
    }

    public function test_usd_to_currency_rate(): void
    {
        $prices = app(PriceCalculator::class);

        $this->assertSame('0.925925925925', $prices->usdToCurrencyRate($this->eur));
        $this->assertSame('0.787037037036', $prices->usdToCurrencyRate($this->gbp));
    }

    public function test_recommends_markup_on_cost_plus_shipping_in_the_chosen_currency(): void
    {
        $this->assertSame('14.99', $this->calculator->recommend('3.47', '5.43', '100', PriceRounding::Ends99, $this->gbp));
        $this->assertSame('14.01', $this->calculator->recommend('3.47', '5.43', '100', PriceRounding::None, $this->gbp));
        $this->assertSame('16.48', $this->calculator->recommend('3.47', '5.43', '100', PriceRounding::None, $this->eur));
    }

    public function test_margin_is_a_percentage_of_the_price(): void
    {
        $this->assertSame('53.27', $this->calculator->margin('14.99', '3.47', '5.43', $this->gbp));
        $this->assertSame('-40.09', $this->calculator->margin('5.00', '3.47', '5.43', $this->gbp));
        $this->assertNull($this->calculator->margin('0', '3.47', '5.43', $this->gbp));
    }

    public function test_converts_usd_amounts(): void
    {
        $this->assertSame('21.65', $this->calculator->inCurrency('27.51', $this->gbp));
    }

    public function test_rounds_half_up(): void
    {
        $this->assertSame('1.24', ListingPriceCalculator::roundHalfUp('1.235', 2));
        $this->assertSame('-1.24', ListingPriceCalculator::roundHalfUp('-1.235', 2));
        $this->assertSame('2', ListingPriceCalculator::roundHalfUp('1.5', 0));
    }
}
```

(27.51 × 0.787037037036 = 21.6513888… → `21.65`.)

- [ ] **Step 2: Run and see it fail**

Run: `vendor/bin/phpunit tests/Feature/Pricing/ListingPriceCalculatorTest.php`
Expected: FAIL — class/method not found.

- [ ] **Step 3: Add `usdToCurrencyRate()` to `PriceCalculator`**

Replace the line `$currencyRate = $currency->default ? '1' : self::decimal($currency->exchange_rate);` in `priceFor()` with `$currencyRate = $this->currencyRate($currency);` and add:

```php
    /**
     * Value of 1 USD in the given currency.
     */
    public function usdToCurrencyRate(Currency $currency): string
    {
        return bcmul($this->usdRate(), $this->currencyRate($currency), self::SCALE);
    }

    private function currencyRate(Currency $currency): string
    {
        return $currency->default ? '1' : self::decimal($currency->exchange_rate);
    }
```

- [ ] **Step 4: Create `src/Pricing/ListingPriceCalculator.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

use Lunar\Models\Currency;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;

/**
 * Prices confirmed by hand on the listing page: recommendation = (CJ cost + shipping) x (1 + markup), in one currency.
 */
final class ListingPriceCalculator
{
    private const SCALE = 12;

    public function __construct(private readonly PriceCalculator $prices) {}

    public function recommend(string $costUsd, string $shippingUsd, string $markupPercent, PriceRounding $rounding, Currency $currency): string
    {
        $minor = $this->prices->priceFor(bcadd($costUsd, $shippingUsd, self::SCALE), $markupPercent, $rounding, $currency);
        $decimals = (int) $currency->decimal_places;

        return bcdiv((string) $minor, bcpow('10', (string) $decimals), $decimals);
    }

    /**
     * Margin as a percentage of the price, or null when the price is not positive.
     */
    public function margin(string $price, string $costUsd, string $shippingUsd, Currency $currency): ?string
    {
        if (bccomp($price, '0', self::SCALE) <= 0) {
            return null;
        }

        $total = bcmul(bcadd($costUsd, $shippingUsd, self::SCALE), $this->prices->usdToCurrencyRate($currency), self::SCALE);
        $ratio = bcdiv(bcsub($price, $total, self::SCALE), $price, self::SCALE);

        return self::roundHalfUp(bcmul($ratio, '100', self::SCALE), 2);
    }

    public function inCurrency(string $usd, Currency $currency): string
    {
        return self::roundHalfUp(bcmul($usd, $this->prices->usdToCurrencyRate($currency), self::SCALE), (int) $currency->decimal_places);
    }

    public static function roundHalfUp(string $value, int $decimals): string
    {
        $offset = '0.'.str_repeat('0', $decimals).'5';

        return str_starts_with($value, '-')
            ? bcsub($value, $offset, $decimals)
            : bcadd($value, $offset, $decimals);
    }
}
```

- [ ] **Step 5: Run the pricing tests and analysis**

Run: `vendor/bin/phpunit tests/Feature/Pricing` then `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: all green (existing `PriceCalculatorTest` still passes).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint src/Pricing/PriceCalculator.php src/Pricing/ListingPriceCalculator.php tests/Feature/Pricing/ListingPriceCalculatorTest.php
git add src/Pricing/PriceCalculator.php src/Pricing/ListingPriceCalculator.php tests/Feature/Pricing/ListingPriceCalculatorTest.php
git commit -m "feat: add listing price recommendation and margin"
```

---

### Task 5: Freight quoter

**Files:**
- Create: `src/Logistics/FreightQuoter.php`
- Test: `tests/Feature/Logistics/FreightQuoterTest.php`

**Interfaces:**
- Consumes: `CjClient::logistics()->freightCalculate(FreightQuery): list<FreightOption>` (Task 2), `Throttle::wait()`, config `lunar-cjdropshipping.freight.cache_ttl` (Task 3).
- Produces:
  - `FreightQuoter::quote(string $productId, string $from, string $to, array $weightsByVid): array` — `$weightsByVid` is `array<array-key, string|null>` (vid => weight in grams); returns `array<array-key, array<string, FreightOption>>` (vid => method name => option). One CJ call per distinct weight (quantity 1, first vid of the group), cached per product/from/to/weights.
  - `FreightQuoter::commonMethods(array $quote, list<string> $vids): list<string>` (static) — method names available for every given vid, in first-seen order.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Logistics;

use Thayron\LunarCjDropshipping\Logistics\FreightQuoter;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class FreightQuoterTest extends TestCase
{
    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cj = FakeCj::install($this->app);
    }

    public function test_quotes_one_variant_per_distinct_weight(): void
    {
        $this->cj
            ->success([
                ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 5.43, 'logisticAging' => '7-12'],
                ['logisticName' => 'USPS+', 'logisticPrice' => 9.10, 'logisticAging' => '4-8'],
            ])
            ->success([
                ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 2.10, 'logisticAging' => '7-12'],
            ]);

        $quote = app(FreightQuoter::class)->quote('p-100', 'CN', 'GB', ['v-1' => '1580', 'v-2' => '120', 'v-3' => '1580']);

        $this->assertSame(['logistic/freightCalculate', 'logistic/freightCalculate'], $this->cj->paths());
        $this->assertSame(['startCountryCode' => 'CN', 'endCountryCode' => 'GB', 'products' => [['vid' => 'v-1', 'quantity' => 1]]], $this->cj->jsonAt(0));
        $this->assertSame(['startCountryCode' => 'CN', 'endCountryCode' => 'GB', 'products' => [['vid' => 'v-2', 'quantity' => 1]]], $this->cj->jsonAt(1));

        $this->assertSame('5.43', $quote['v-1']['CJPacket Ordinary']->priceUsd);
        $this->assertSame('5.43', $quote['v-3']['CJPacket Ordinary']->priceUsd);
        $this->assertSame('2.10', $quote['v-2']['CJPacket Ordinary']->priceUsd);
        $this->assertArrayNotHasKey('USPS+', $quote['v-2']);

        $this->assertSame(['CJPacket Ordinary'], FreightQuoter::commonMethods($quote, ['v-1', 'v-2']));
        $this->assertSame(['CJPacket Ordinary', 'USPS+'], FreightQuoter::commonMethods($quote, ['v-1', 'v-3']));
        $this->assertSame([], FreightQuoter::commonMethods($quote, []));
    }

    public function test_caches_quotes(): void
    {
        $this->cj->success([['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 5.43, 'logisticAging' => '7-12']]);

        app(FreightQuoter::class)->quote('p-100', 'CN', 'GB', ['v-1' => '1580']);
        $again = app(FreightQuoter::class)->quote('p-100', 'CN', 'GB', ['v-1' => '1580']);

        $this->assertCount(1, $this->cj->requests());
        $this->assertSame('5.43', $again['v-1']['CJPacket Ordinary']->priceUsd);
    }
}
```

Note: FakeCj's `success()` sends floats like `2.10` as JSON `2.1`; if `'2.10'` fails, change the fixture value to the string `'2.10'` (CJ sends either).

- [ ] **Step 2: Run and see it fail**

Run: `vendor/bin/phpunit tests/Feature/Logistics/FreightQuoterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `src/Logistics/FreightQuoter.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Logistics;

use Illuminate\Support\Facades\Cache;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Criteria\FreightQuery;
use Thayron\CjDropshipping\Data\FreightOption;
use Thayron\LunarCjDropshipping\Support\Throttle;

/**
 * Shipping quotes per variant. Variants with the same weight share one CJ call (10 quota points each).
 */
final class FreightQuoter
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
    ) {}

    /**
     * @param  array<array-key, string|null>  $weightsByVid  vid => weight in grams
     * @return array<array-key, array<string, FreightOption>> vid => method name => option
     */
    public function quote(string $productId, string $from, string $to, array $weightsByVid): array
    {
        $key = 'lunar-cjdropshipping.freight.'.md5((string) json_encode([$productId, strtoupper($from), strtoupper($to), $weightsByVid]));

        /** @var array<array-key, array<string, FreightOption>> */
        return Cache::remember($key, (int) config('lunar-cjdropshipping.freight.cache_ttl', 21600), function () use ($from, $to, $weightsByVid): array {
            /** @var array<string, list<string>> $groups */
            $groups = [];

            foreach ($weightsByVid as $vid => $weight) {
                $groups[(string) ($weight ?? '')][] = (string) $vid;
            }

            $quote = [];

            foreach ($groups as $vids) {
                $this->throttle->wait();
                $byName = [];

                foreach ($this->cj->logistics()->freightCalculate(FreightQuery::make($from, $to)->product($vids[0])) as $option) {
                    $byName[$option->name] = $option;
                }

                foreach ($vids as $vid) {
                    $quote[$vid] = $byName;
                }
            }

            return $quote;
        });
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $quote  vid => method name => anything
     * @param  list<string>  $vids
     * @return list<string>
     */
    public static function commonMethods(array $quote, array $vids): array
    {
        if ($vids === []) {
            return [];
        }

        $methods = null;

        foreach ($vids as $vid) {
            $names = array_map('strval', array_keys($quote[$vid] ?? []));
            $methods = $methods === null ? $names : array_values(array_filter($methods, fn (string $name): bool => in_array($name, $names, true)));
        }

        return $methods ?? [];
    }
}
```

- [ ] **Step 4: Run tests and analysis**

Run: `vendor/bin/phpunit tests/Feature/Logistics` then `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: green.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint src/Logistics/FreightQuoter.php tests/Feature/Logistics/FreightQuoterTest.php
git add src/Logistics/FreightQuoter.php tests/Feature/Logistics/FreightQuoterTest.php
git commit -m "feat: quote CJ shipping per variant weight with caching"
```

---

### Task 6: Rules feed one import list; list screen loses direct import

**Files:**
- Modify: `src/Models/Candidate.php` (add `fillFromSummary()`)
- Modify: `src/Actions/DiscoverCandidates.php`
- Modify: `src/Filament/Resources/CandidateResource.php`
- Modify: `lang/en/admin.php`, `lang/pt_BR/admin.php`
- Test: `tests/Feature/Discovery/DiscoverCandidatesTest.php` (add one test)
- Test: `tests/Filament/CandidateResourceTest.php` (rewrite import-related tests)

**Interfaces:**
- Consumes: `CandidateSource` (Task 3).
- Produces: `Candidate::fillFromSummary(ProductSummary $summary): static` — fills `cj_product_id`, `cj_sku`, `name` (limited to 255), `image_url`, `cost_usd` (`CostParser::lowest`), `warehouse_stock`, `cj_category_id`, `payload`. Does not save.
- `CandidateResource::approve()` and the `import` / `retry` table actions and `import` bulk action are removed. Task 9 adds the `confirm` action.

- [ ] **Step 1: Write the failing discovery test**

Add to `tests/Feature/Discovery/DiscoverCandidatesTest.php` (add `use Thayron\LunarCjDropshipping\Enums\CandidateSource;` if missing):

```php
    public function test_keeps_catalog_items_and_never_duplicates_a_product(): void
    {
        $rule = $this->rule(['keyword' => 'x']);
        Candidate::create([
            'cj_product_id' => 'p-100', 'source' => CandidateSource::Catalog, 'name' => 'Old name',
            'status' => CandidateStatus::Pending, 'payload' => [], 'discovered_at' => now()->subDay(),
        ]);
        $this->cj->fixture('list-v2-page');

        app(DiscoverCandidates::class)->handle($rule);

        $catalogItem = Candidate::query()->where('cj_product_id', 'p-100')->sole();
        $this->assertNull($catalogItem->import_rule_id);
        $this->assertSame(CandidateSource::Catalog, $catalogItem->source);
        $this->assertSame('Magnetic Phone Case', $catalogItem->name);

        $ruleItem = Candidate::query()->where('cj_product_id', 'p-200')->sole();
        $this->assertSame($rule->id, $ruleItem->import_rule_id);
        $this->assertSame(CandidateSource::Rule, $ruleItem->source);
    }
```

- [ ] **Step 2: Run and see it fail**

Run: `vendor/bin/phpunit tests/Feature/Discovery/DiscoverCandidatesTest.php --filter test_keeps_catalog_items`
Expected: FAIL — unique constraint violation (discovery still looks up by rule + product).

- [ ] **Step 3: Add `fillFromSummary()` to `src/Models/Candidate.php`**

Imports: `use Illuminate\Support\Str;`, `use Thayron\CjDropshipping\Data\ProductSummary;`, `use Thayron\LunarCjDropshipping\Pricing\CostParser;`

```php
    public function fillFromSummary(ProductSummary $summary): static
    {
        return $this->fill([
            'cj_product_id' => $summary->id,
            'cj_sku' => $summary->sku,
            'name' => Str::limit($summary->name ?? $summary->sku ?? $summary->id, 255, ''),
            'image_url' => $summary->image,
            'cost_usd' => CostParser::lowest($summary->sellPrice),
            'warehouse_stock' => $summary->warehouseInventory,
            'cj_category_id' => $summary->categoryId,
            'payload' => $summary->raw(),
        ]);
    }
```

- [ ] **Step 4: Upsert by product in `src/Actions/DiscoverCandidates.php`**

Replace the body of `upsert()` from `$candidate = Candidate::query()->firstOrNew(...)` through the `if ($isNew) { $candidate->discovered_at = now(); }` block with:

```php
        $candidate = Candidate::query()->firstOrNew(['cj_product_id' => $summary->id]);

        if ($candidate->exists && $candidate->status === CandidateStatus::Ignored) {
            return 'skipped_ignored';
        }

        $link = ProductLink::query()->whereHas('product')->where('cj_product_id', $summary->id)->first();
        $isNew = ! $candidate->exists;

        $candidate->fillFromSummary($summary);

        if ($link !== null) {
            $candidate->status = CandidateStatus::Imported;
            $candidate->lunar_product_id = $link->lunar_product_id;
        } elseif ($isNew || $candidate->status === CandidateStatus::Imported) {
            $candidate->status = CandidateStatus::Pending;
            $candidate->lunar_product_id = null;
        }

        if ($isNew) {
            $candidate->import_rule_id = $rule->id;
            $candidate->source = CandidateSource::Rule;
            $candidate->discovered_at = now();
        }
```

Add `use Thayron\LunarCjDropshipping\Enums\CandidateSource;` and remove the now-unused `use Illuminate\Support\Str;` and `use Thayron\LunarCjDropshipping\Pricing\CostParser;` only if nothing else in the file uses them (`matches()` still uses `CostParser` — keep it).

- [ ] **Step 5: Run the discovery tests**

Run: `vendor/bin/phpunit tests/Feature/Discovery`
Expected: all green.

- [ ] **Step 6: Rewrite the import-list resource tests**

In `tests/Filament/CandidateResourceTest.php`:
- Delete `test_shows_pending_candidates_by_default_with_calculated_prices`, `test_bulk_import_approves_and_queues_candidates` and `test_retry_action_queues_failed_candidates`.
- Add (with `use Thayron\LunarCjDropshipping\Enums\CandidateSource;`):

```php
    public function test_lists_pending_items_from_rules_and_from_the_catalog(): void
    {
        $fromRule = $this->candidate('p-1', CandidateStatus::Pending);
        $fromCatalog = Candidate::create([
            'cj_product_id' => 'p-2', 'source' => CandidateSource::Catalog, 'name' => 'Catalog product',
            'status' => CandidateStatus::Pending, 'payload' => [], 'discovered_at' => now(),
        ]);
        $ignored = $this->candidate('p-3', CandidateStatus::Ignored);

        Livewire::test(ListCandidates::class)
            ->assertCanSeeTableRecords([$fromRule, $fromCatalog])
            ->assertCanNotSeeTableRecords([$ignored])
            ->assertSee(__('lunar-cjdropshipping::admin.candidates.source.catalog'))
            ->filterTable('source', 'catalog')
            ->assertCanSeeTableRecords([$fromCatalog])
            ->assertCanNotSeeTableRecords([$fromRule]);
    }

    public function test_has_no_direct_import_actions(): void
    {
        Livewire::test(ListCandidates::class)
            ->assertTableActionDoesNotExist('import')
            ->assertTableActionDoesNotExist('retry')
            ->assertTableBulkActionDoesNotExist('import');
    }
```

Remove `use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;` if no remaining test uses it.

- [ ] **Step 7: Run and see them fail**

Run: `vendor/bin/phpunit tests/Filament/CandidateResourceTest.php`
Expected: FAIL — no `source` filter; `import` action still exists.

- [ ] **Step 8: Update `src/Filament/Resources/CandidateResource.php`**

- Delete the `approve()` method, the `sale_price` column, the `import` and `retry` row actions and the `import` bulk action. Remove unused imports (`PricePreview`, `ImportProductJob`, `Notification` if unused).
- Replace the rule column with:

```php
                Tables\Columns\TextColumn::make('importRule.name')->label($column('rule'))->placeholder('—')->toggleable(),
```

- Add after the `cj_sku` column:

```php
                Tables\Columns\TextColumn::make('source')
                    ->label($column('source'))
                    ->badge()
                    ->formatStateUsing(fn (CandidateSource $state): string => __('lunar-cjdropshipping::admin.candidates.source.'.$state->value))
                    ->color(fn (CandidateSource $state): string => $state === CandidateSource::Catalog ? 'info' : 'gray'),
```

- Add after the `status` filter:

```php
                Tables\Filters\SelectFilter::make('source')
                    ->label($column('source'))
                    ->options(collect(CandidateSource::cases())->mapWithKeys(fn (CandidateSource $source) => [$source->value => __('lunar-cjdropshipping::admin.candidates.source.'.$source->value)])->all()),
```

- Add `use Thayron\LunarCjDropshipping\Enums\CandidateSource;`.
- Before deleting `approve()`, run `grep -rn "approve(" src tests` and confirm the resource and the deleted tests were its only callers.

- [ ] **Step 9: Translations**

`lang/en/admin.php` → `candidates.label` = `'List item'`, `candidates.plural_label` = `'Import list'`, add `candidates.columns.source` = `'Source'`; remove `candidates.columns.price`, `candidates.actions.import`, `candidates.actions.retry`, `candidates.actions.import_queued`.

`lang/pt_BR/admin.php` → `candidates.label` = `'Item da lista'`, `candidates.plural_label` = `'Lista de importação'`, add `candidates.columns.source` = `'Origem'`; remove the same keys.

- [ ] **Step 10: Run the suite and analysis**

Run: `vendor/bin/phpunit` then `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: green.

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint src/Models/Candidate.php src/Actions/DiscoverCandidates.php src/Filament/Resources/CandidateResource.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Discovery/DiscoverCandidatesTest.php tests/Filament/CandidateResourceTest.php
git add src/Models/Candidate.php src/Actions/DiscoverCandidates.php src/Filament/Resources/CandidateResource.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Discovery/DiscoverCandidatesTest.php tests/Filament/CandidateResourceTest.php
git commit -m "feat: rules feed a single import list without direct import"
```

---

### Task 7: CJ Catalog page

**Files:**
- Create: `src/Actions/SearchCatalog.php`
- Create: `src/Actions/AddCandidateFromCatalog.php`
- Create: `src/Filament/Pages/CjCatalog.php`
- Create: `resources/views/filament/pages/cj-catalog.blade.php`
- Modify: `src/LunarCjDropshippingServiceProvider.php` (load views)
- Modify: `src/Filament/CjDropshippingPlugin.php` (register page)
- Modify: `lang/en/admin.php`, `lang/pt_BR/admin.php`
- Test: `tests/Feature/Catalog/SearchCatalogTest.php`
- Test: `tests/Filament/CjCatalogTest.php`

**Interfaces:**
- Consumes: `Candidate::fillFromSummary()` (Task 6), `CandidateSource` (Task 3), `CjCatalogOptions::categories()/countries()`, SDK `ProductSearch`, `Paginated` (`$items`, `hasMorePages()`), `ProductSummary`.
- Produces:
  - `SearchCatalog::handle(array $filters, int $page): Paginated` — `$filters` is `array{keyword?: string|null, category_id?: string|null, country_code?: string|null, min_price?: string|null, max_price?: string|null}`; 24 per page; cached 600 s per query.
  - `AddCandidateFromCatalog::handle(ProductSummary $summary): ?Candidate` — `null` when the product is already in the list.
  - Filament page `CjCatalog` (Livewire): public `array $filters`, `int $page`; methods `search()`, `previousPage()`, `nextPage()`, `addToList(string $productId)`.

- [ ] **Step 1: Write the failing action test**

`tests/Feature/Catalog/SearchCatalogTest.php`:

```php
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
```

- [ ] **Step 2: Run and see it fail**

Run: `vendor/bin/phpunit tests/Feature/Catalog/SearchCatalogTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement `src/Actions/SearchCatalog.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Support\Facades\Cache;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Criteria\ProductSearch;
use Thayron\CjDropshipping\Data\Paginated;
use Thayron\CjDropshipping\Data\ProductSummary;
use Thayron\LunarCjDropshipping\Support\Throttle;

final class SearchCatalog
{
    public const PER_PAGE = 24;

    private const TTL = 600;

    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
    ) {}

    /**
     * @param  array{keyword?: string|null, category_id?: string|null, country_code?: string|null, min_price?: string|null, max_price?: string|null}  $filters
     * @return Paginated<ProductSummary>
     */
    public function handle(array $filters, int $page): Paginated
    {
        $criteria = ProductSearch::make()->perPage(self::PER_PAGE)->page(max(1, min($page, ProductSearch::MAX_PAGE)));

        if (filled($filters['keyword'] ?? null)) {
            $criteria = $criteria->keyword((string) $filters['keyword']);
        }

        if (filled($filters['category_id'] ?? null)) {
            $criteria = $criteria->category((string) $filters['category_id']);
        }

        if (filled($filters['country_code'] ?? null)) {
            $criteria = $criteria->country((string) $filters['country_code']);
        }

        $min = filled($filters['min_price'] ?? null) ? (string) $filters['min_price'] : null;
        $max = filled($filters['max_price'] ?? null) ? (string) $filters['max_price'] : null;

        if ($min !== null || $max !== null) {
            $criteria = $criteria->priceBetween($min, $max);
        }

        /** @var Paginated<ProductSummary> */
        return Cache::remember('lunar-cjdropshipping.catalog.'.md5((string) json_encode($criteria->toQuery())), self::TTL, function () use ($criteria): Paginated {
            $this->throttle->wait();

            return $this->cj->products()->search($criteria);
        });
    }
}
```

- [ ] **Step 4: Implement `src/Actions/AddCandidateFromCatalog.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Thayron\CjDropshipping\Data\ProductSummary;
use Thayron\LunarCjDropshipping\Enums\CandidateSource;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ProductLink;

final class AddCandidateFromCatalog
{
    /**
     * Adds the product to the import list; null when it is already there.
     */
    public function handle(ProductSummary $summary): ?Candidate
    {
        if (Candidate::query()->where('cj_product_id', $summary->id)->exists()) {
            return null;
        }

        $candidate = new Candidate;
        $candidate->fillFromSummary($summary);
        $candidate->source = CandidateSource::Catalog;
        $candidate->status = CandidateStatus::Pending;
        $candidate->discovered_at = now();

        $link = ProductLink::query()->whereHas('product')->where('cj_product_id', $summary->id)->first();

        if ($link !== null) {
            $candidate->status = CandidateStatus::Imported;
            $candidate->lunar_product_id = $link->lunar_product_id;
        }

        try {
            $candidate->save();
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $candidate;
    }
}
```

- [ ] **Step 5: Run the action tests**

Run: `vendor/bin/phpunit tests/Feature/Catalog/SearchCatalogTest.php`
Expected: PASS. (If `size`/`page` query values differ from `'24'`/`'2'`, check `ProductSearch::toQuery()` keys and fix the test to the SDK's real keys.)

- [ ] **Step 6: Write the failing page test**

`tests/Filament/CjCatalogTest.php`:

```php
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
```

- [ ] **Step 7: Run and see it fail**

Run: `vendor/bin/phpunit tests/Filament/CjCatalogTest.php`
Expected: FAIL — `CjCatalog` not found.

- [ ] **Step 8: Load package views**

In `src/LunarCjDropshippingServiceProvider.php` `boot()`, after `loadTranslationsFrom(...)`:

```php
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lunar-cjdropshipping');
```

- [ ] **Step 9: Implement `src/Filament/Pages/CjCatalog.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Lunar\Admin\Support\Pages\BasePage;
use Thayron\CjDropshipping\Data\ProductSummary;
use Thayron\LunarCjDropshipping\Actions\AddCandidateFromCatalog;
use Thayron\LunarCjDropshipping\Actions\SearchCatalog;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Filament\Support\CjCatalogOptions;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Throwable;

class CjCatalog extends BasePage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $permission = 'catalog:manage-products';

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'lunar-cjdropshipping::filament.pages.cj-catalog';

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    public int $page = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('lunar-cjdropshipping::admin.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('lunar-cjdropshipping::admin.catalog.title');
    }

    public function getTitle(): string
    {
        return __('lunar-cjdropshipping::admin.catalog.title');
    }

    public function mount(): void
    {
        parent::mount();

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        $label = fn (string $key): string => __("lunar-cjdropshipping::admin.catalog.filters.{$key}");

        return $form
            ->statePath('filters')
            ->columns(5)
            ->schema([
                TextInput::make('keyword')->label($label('keyword')),
                Select::make('category_id')->label($label('category'))->options(fn (): array => CjCatalogOptions::categories())->searchable(),
                Select::make('country_code')->label($label('ship_from'))->options(fn (): array => CjCatalogOptions::countries())->searchable(),
                TextInput::make('min_price')->label($label('min_price'))->numeric()->minValue(0),
                TextInput::make('max_price')->label($label('max_price'))->numeric()->minValue(0),
            ]);
    }

    public function search(): void
    {
        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function addToList(string $productId, SearchCatalog $search, AddCandidateFromCatalog $add): void
    {
        $summary = collect($search->handle($this->filterValues(), $this->page)->items)
            ->first(fn (ProductSummary $item): bool => $item->id === $productId);

        if ($summary === null) {
            Notification::make()->title(__('lunar-cjdropshipping::admin.catalog.not_found'))->danger()->send();

            return;
        }

        if ($add->handle($summary) === null) {
            Notification::make()->title(__('lunar-cjdropshipping::admin.catalog.already'))->warning()->send();

            return;
        }

        Notification::make()->title(__('lunar-cjdropshipping::admin.catalog.added'))->success()->send();
    }

    /**
     * @return array{products: list<ProductSummary>, hasMore: bool, error: string|null, statuses: array<string, CandidateStatus>}
     */
    protected function getViewData(): array
    {
        try {
            $result = app(SearchCatalog::class)->handle($this->filterValues(), $this->page);
        } catch (Throwable $exception) {
            return [
                'products' => [],
                'hasMore' => false,
                'error' => __('lunar-cjdropshipping::admin.catalog.error', ['message' => $exception->getMessage()]),
                'statuses' => [],
            ];
        }

        $products = array_values($result->items);
        $ids = array_map(fn (ProductSummary $product): string => $product->id, $products);

        $statuses = Candidate::query()
            ->whereIn('cj_product_id', $ids)
            ->get(['cj_product_id', 'status'])
            ->mapWithKeys(fn (Candidate $candidate): array => [$candidate->cj_product_id => $candidate->status])
            ->all();

        return ['products' => $products, 'hasMore' => $result->hasMorePages(), 'error' => null, 'statuses' => $statuses];
    }

    /**
     * @return array{keyword: string|null, category_id: string|null, country_code: string|null, min_price: string|null, max_price: string|null}
     */
    private function filterValues(): array
    {
        $value = fn (string $key): ?string => filled($this->filters[$key] ?? null) ? (string) $this->filters[$key] : null;

        return [
            'keyword' => $value('keyword'),
            'category_id' => $value('category_id'),
            'country_code' => $value('country_code'),
            'min_price' => $value('min_price'),
            'max_price' => $value('max_price'),
        ];
    }
}
```

- [ ] **Step 10: Create `resources/views/filament/pages/cj-catalog.blade.php`**

```blade
<x-filament-panels::page>
    <form wire:submit="search" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
            {{ __('lunar-cjdropshipping::admin.catalog.search') }}
        </x-filament::button>
    </form>

    @if ($error)
        <x-filament::section>
            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $error }}</p>
        </x-filament::section>
    @elseif ($products === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('lunar-cjdropshipping::admin.catalog.empty') }}</p>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(13rem,1fr));gap:1rem">
            @foreach ($products as $product)
                @php($status = $statuses[$product->id] ?? null)

                <x-filament::section wire:key="cj-product-{{ $product->id }}">
                    @if ($product->image)
                        <img src="{{ $product->image }}" alt="" loading="lazy" style="aspect-ratio:1/1;width:100%;object-fit:cover;border-radius:0.5rem">
                    @endif

                    <p class="mt-2 text-sm font-medium text-gray-950 dark:text-white" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden" title="{{ $product->name }}">
                        {{ $product->name ?? $product->sku ?? $product->id }}
                    </p>

                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        US$ {{ $product->sellPrice ?? '—' }}
                        · {{ __('lunar-cjdropshipping::admin.catalog.stock') }}: {{ $product->warehouseInventory ?? 0 }}
                    </p>

                    <div class="mt-3">
                        @if ($status === \Thayron\LunarCjDropshipping\Enums\CandidateStatus::Imported)
                            <x-filament::badge color="success">{{ __('lunar-cjdropshipping::admin.catalog.imported') }}</x-filament::badge>
                        @elseif ($status !== null)
                            <x-filament::badge color="gray">{{ __('lunar-cjdropshipping::admin.catalog.in_list') }}</x-filament::badge>
                        @else
                            <x-filament::button size="sm" icon="heroicon-o-plus" wire:click="addToList(@js($product->id))">
                                {{ __('lunar-cjdropshipping::admin.catalog.add') }}
                            </x-filament::button>
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <div class="flex items-center justify-between gap-3">
            <x-filament::button color="gray" wire:click="previousPage" :disabled="$this->page <= 1">
                {{ __('lunar-cjdropshipping::admin.catalog.previous') }}
            </x-filament::button>

            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $this->page }}</span>

            <x-filament::button color="gray" wire:click="nextPage" :disabled="! $hasMore">
                {{ __('lunar-cjdropshipping::admin.catalog.next') }}
            </x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 11: Register the page in `src/Filament/CjDropshippingPlugin.php`**

Add `use Thayron\LunarCjDropshipping\Filament\Pages\CjCatalog;` and in `register()` after `$panel->resources([...]);`:

```php
        $panel->pages([
            CjCatalog::class,
        ]);
```

- [ ] **Step 12: Translations**

`lang/en/admin.php`, new top-level key:

```php
    'catalog' => [
        'title' => 'CJ catalog',
        'filters' => [
            'keyword' => 'Keyword',
            'category' => 'Category',
            'ship_from' => 'Ship from',
            'min_price' => 'Min price (US$)',
            'max_price' => 'Max price (US$)',
        ],
        'search' => 'Search',
        'previous' => 'Previous',
        'next' => 'Next',
        'stock' => 'Stock',
        'add' => 'List',
        'in_list' => 'In list',
        'imported' => 'Imported',
        'added' => 'Added to the import list.',
        'already' => 'This product is already in the import list.',
        'not_found' => 'This product is no longer in the results. Search again.',
        'empty' => 'No products found.',
        'error' => 'Could not load the CJ catalog: :message',
    ],
```

`lang/pt_BR/admin.php`:

```php
    'catalog' => [
        'title' => 'Catálogo CJ',
        'filters' => [
            'keyword' => 'Palavra-chave',
            'category' => 'Categoria',
            'ship_from' => 'Enviar de',
            'min_price' => 'Preço mínimo (US$)',
            'max_price' => 'Preço máximo (US$)',
        ],
        'search' => 'Buscar',
        'previous' => 'Anterior',
        'next' => 'Próxima',
        'stock' => 'Estoque',
        'add' => 'Listar',
        'in_list' => 'Na lista',
        'imported' => 'Importado',
        'added' => 'Adicionado à lista de importação.',
        'already' => 'Este produto já está na lista de importação.',
        'not_found' => 'Este produto não está mais nos resultados. Busque novamente.',
        'empty' => 'Nenhum produto encontrado.',
        'error' => 'Não foi possível carregar o catálogo da CJ: :message',
    ],
```

- [ ] **Step 13: Run tests and analysis**

Run: `vendor/bin/phpunit` then `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: green.

- [ ] **Step 14: Commit**

```bash
vendor/bin/pint src/Actions/SearchCatalog.php src/Actions/AddCandidateFromCatalog.php src/Filament/Pages/CjCatalog.php src/LunarCjDropshippingServiceProvider.php src/Filament/CjDropshippingPlugin.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Catalog/SearchCatalogTest.php tests/Filament/CjCatalogTest.php
git add src/Actions/SearchCatalog.php src/Actions/AddCandidateFromCatalog.php src/Filament/Pages/CjCatalog.php resources/views/filament/pages/cj-catalog.blade.php src/LunarCjDropshippingServiceProvider.php src/Filament/CjDropshippingPlugin.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Catalog/SearchCatalogTest.php tests/Filament/CjCatalogTest.php
git commit -m "feat: browse the CJ catalog and add products to the import list"
```

---

### Task 8: Listing value objects and the confirm action

**Files:**
- Create: `src/Listing/Listing.php`
- Create: `src/Listing/ListingVariant.php`
- Create: `src/Exceptions/ListingException.php`
- Create: `src/Actions/ConfirmListing.php`
- Modify: `lang/en/admin.php`, `lang/pt_BR/admin.php`
- Test: `tests/Feature/Listing/ConfirmListingTest.php`

**Interfaces:**
- Consumes: `ListingPriceCalculator::margin()` (Task 4), `CandidateStatus`, `ImportProductJob`, `PriceRounding`.
- Produces:
  - `ListingVariant` readonly: `string $vid`, `bool $selected`, `?string $costUsd`, `?string $shippingCostUsd`, `?string $price`; `fromArray(array): self`; `toArray(): array{vid: string, selected: bool, cost_usd: string|null, shipping_cost_usd: string|null, price: string|null}`.
  - `Listing` readonly: `string $name`, `string $shipFromCountry`, `string $shipToCountry`, `string $currencyCode`, `string $shippingMethod`, `string $markupPercent`, `PriceRounding $rounding`, `int $productTypeId`, `?int $brandId`, `?int $collectionId`, `list<ListingVariant> $variants`; `fromArray(array): self`; `toArray(): array` (keys exactly as the spec JSON: `name, ship_from_country, ship_to_country, currency_code, shipping_method, markup_percent, rounding, product_type_id, brand_id, collection_id, variants`); `selectedVariants(): list<ListingVariant>`; `skippedVariantIds(): list<string>`.
  - `ListingException::because(string $key, array $replace = []): self` — message from `lunar-cjdropshipping::admin.listing.errors.{key}`.
  - `ConfirmListing::handle(Candidate $candidate, Listing $listing, bool $acceptNegativeMargin): void` — validates, saves `name`, `listing`, `listed_at`, `status = Approved`, `error = null`, then dispatches `ImportProductJob`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Listing/ConfirmListingTest.php`:

```php
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
```

- [ ] **Step 2: Run and see it fail**

Run: `vendor/bin/phpunit tests/Feature/Listing/ConfirmListingTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create `src/Listing/ListingVariant.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Listing;

final readonly class ListingVariant
{
    /**
     * @param  string|null  $costUsd  CJ variant cost, USD.
     * @param  string|null  $shippingCostUsd  Shipping of the chosen method for this variant, USD.
     * @param  string|null  $price  Confirmed sale price in the listing currency (major units).
     */
    public function __construct(
        public string $vid,
        public bool $selected,
        public ?string $costUsd,
        public ?string $shippingCostUsd,
        public ?string $price,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['vid'] ?? ''),
            (bool) ($data['selected'] ?? false),
            self::decimal($data['cost_usd'] ?? null),
            self::decimal($data['shipping_cost_usd'] ?? null),
            self::decimal($data['price'] ?? null),
        );
    }

    /**
     * @return array{vid: string, selected: bool, cost_usd: string|null, shipping_cost_usd: string|null, price: string|null}
     */
    public function toArray(): array
    {
        return [
            'vid' => $this->vid,
            'selected' => $this->selected,
            'cost_usd' => $this->costUsd,
            'shipping_cost_usd' => $this->shippingCostUsd,
            'price' => $this->price,
        ];
    }

    private static function decimal(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && (string) $value !== '' ? (string) $value : null;
    }
}
```

- [ ] **Step 4: Create `src/Listing/Listing.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Listing;

use Thayron\LunarCjDropshipping\Enums\PriceRounding;

/**
 * A product confirmed by hand on the listing page, stored as JSON on the import list item.
 */
final readonly class Listing
{
    /**
     * @param  list<ListingVariant>  $variants
     */
    public function __construct(
        public string $name,
        public string $shipFromCountry,
        public string $shipToCountry,
        public string $currencyCode,
        public string $shippingMethod,
        public string $markupPercent,
        public PriceRounding $rounding,
        public int $productTypeId,
        public ?int $brandId,
        public ?int $collectionId,
        public array $variants,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $variants = is_array($data['variants'] ?? null) ? $data['variants'] : [];

        return new self(
            (string) ($data['name'] ?? ''),
            strtoupper((string) ($data['ship_from_country'] ?? '')),
            strtoupper((string) ($data['ship_to_country'] ?? '')),
            strtoupper((string) ($data['currency_code'] ?? '')),
            (string) ($data['shipping_method'] ?? ''),
            is_numeric($data['markup_percent'] ?? null) ? (string) $data['markup_percent'] : '0',
            PriceRounding::tryFrom((string) ($data['rounding'] ?? '')) ?? PriceRounding::None,
            (int) ($data['product_type_id'] ?? 0),
            filled($data['brand_id'] ?? null) ? (int) $data['brand_id'] : null,
            filled($data['collection_id'] ?? null) ? (int) $data['collection_id'] : null,
            array_values(array_map(ListingVariant::fromArray(...), array_filter($variants, is_array(...)))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'ship_from_country' => $this->shipFromCountry,
            'ship_to_country' => $this->shipToCountry,
            'currency_code' => $this->currencyCode,
            'shipping_method' => $this->shippingMethod,
            'markup_percent' => $this->markupPercent,
            'rounding' => $this->rounding->value,
            'product_type_id' => $this->productTypeId,
            'brand_id' => $this->brandId,
            'collection_id' => $this->collectionId,
            'variants' => array_map(fn (ListingVariant $variant): array => $variant->toArray(), $this->variants),
        ];
    }

    /**
     * @return list<ListingVariant>
     */
    public function selectedVariants(): array
    {
        return array_values(array_filter($this->variants, fn (ListingVariant $variant): bool => $variant->selected));
    }

    /**
     * @return list<string>
     */
    public function skippedVariantIds(): array
    {
        return array_values(array_map(
            fn (ListingVariant $variant): string => $variant->vid,
            array_filter($this->variants, fn (ListingVariant $variant): bool => ! $variant->selected),
        ));
    }
}
```

- [ ] **Step 5: Create `src/Exceptions/ListingException.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Exceptions;

use RuntimeException;

final class ListingException extends RuntimeException
{
    /**
     * @param  array<string, string>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self((string) __("lunar-cjdropshipping::admin.listing.errors.{$key}", $replace));
    }
}
```

- [ ] **Step 6: Create `src/Actions/ConfirmListing.php`**

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Lunar\Models\Currency;
use Lunar\Models\ProductType;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Exceptions\ListingException;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Listing\Listing;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Pricing\ListingPriceCalculator;

final class ConfirmListing
{
    public function __construct(private readonly ListingPriceCalculator $prices) {}

    /**
     * @throws ListingException
     */
    public function handle(Candidate $candidate, Listing $listing, bool $acceptNegativeMargin): void
    {
        if (! in_array($candidate->status, [CandidateStatus::Pending, CandidateStatus::Failed], true)) {
            throw ListingException::because('not_confirmable');
        }

        $name = trim($listing->name);

        if ($name === '') {
            throw ListingException::because('name_required');
        }

        if (mb_strlen($name) > 255) {
            throw ListingException::because('name_too_long');
        }

        if (preg_match('/^[A-Z]{2}$/', $listing->shipFromCountry) !== 1 || preg_match('/^[A-Z]{2}$/', $listing->shipToCountry) !== 1) {
            throw ListingException::because('countries_required');
        }

        $currency = Currency::query()->where('code', $listing->currencyCode)->where('enabled', true)->first()
            ?? throw ListingException::because('currency_invalid');

        if (trim($listing->shippingMethod) === '') {
            throw ListingException::because('method_required');
        }

        if (! ProductType::query()->whereKey($listing->productTypeId)->exists()) {
            throw ListingException::because('product_type_required');
        }

        $selected = $listing->selectedVariants();

        if ($selected === []) {
            throw ListingException::because('no_variants');
        }

        $negative = false;

        foreach ($selected as $variant) {
            if ($variant->price === null || ! is_numeric($variant->price) || bccomp($variant->price, '0', 2) <= 0) {
                throw ListingException::because('price_required');
            }

            if ($variant->shippingCostUsd === null) {
                throw ListingException::because('shipping_missing', ['sku' => $variant->vid]);
            }

            if ($variant->costUsd === null) {
                throw ListingException::because('cost_missing', ['sku' => $variant->vid]);
            }

            $margin = $this->prices->margin($variant->price, $variant->costUsd, $variant->shippingCostUsd, $currency);
            $negative = $negative || ($margin !== null && bccomp($margin, '0', 2) < 0);
        }

        if ($negative && ! $acceptNegativeMargin) {
            throw ListingException::because('negative_margin');
        }

        $candidate->forceFill([
            'name' => $name,
            'listing' => $listing->toArray(),
            'listed_at' => now(),
            'status' => CandidateStatus::Approved,
            'error' => null,
        ])->save();

        ImportProductJob::dispatch($candidate);
    }
}
```

- [ ] **Step 7: Translations**

`lang/en/admin.php`, new top-level key (Task 9 adds more keys to this same array):

```php
    'listing' => [
        'errors' => [
            'not_confirmable' => 'Only pending or failed items can be confirmed.',
            'name_required' => 'Enter the product name.',
            'name_too_long' => 'The name may not be longer than 255 characters.',
            'countries_required' => 'Choose where to ship from and to.',
            'currency_invalid' => 'Choose an enabled currency.',
            'method_required' => 'Quote shipping and choose a method.',
            'product_type_required' => 'Choose a product type.',
            'no_variants' => 'Select at least one variant.',
            'price_required' => 'Every selected variant needs a price above zero.',
            'shipping_missing' => 'The chosen method cannot ship variant :sku.',
            'cost_missing' => 'CJ has no cost for variant :sku.',
            'negative_margin' => 'Some prices are below cost. Tick "I accept a negative margin" to continue.',
        ],
    ],
```

`lang/pt_BR/admin.php`:

```php
    'listing' => [
        'errors' => [
            'not_confirmable' => 'Só itens pendentes ou com falha podem ser confirmados.',
            'name_required' => 'Informe o nome do produto.',
            'name_too_long' => 'O nome pode ter no máximo 255 caracteres.',
            'countries_required' => 'Escolha de onde e para onde enviar.',
            'currency_invalid' => 'Escolha uma moeda ativa.',
            'method_required' => 'Cote o frete e escolha um método.',
            'product_type_required' => 'Escolha um tipo de produto.',
            'no_variants' => 'Selecione pelo menos uma variação.',
            'price_required' => 'Toda variação selecionada precisa de um preço acima de zero.',
            'shipping_missing' => 'O método escolhido não envia a variação :sku.',
            'cost_missing' => 'A CJ não tem custo para a variação :sku.',
            'negative_margin' => 'Há preços abaixo do custo. Marque "Aceito margem negativa" para continuar.',
        ],
    ],
```

- [ ] **Step 8: Run tests and analysis**

Run: `vendor/bin/phpunit tests/Feature/Listing` then `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: green.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint src/Listing/Listing.php src/Listing/ListingVariant.php src/Exceptions/ListingException.php src/Actions/ConfirmListing.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Listing/ConfirmListingTest.php
git add src/Listing/Listing.php src/Listing/ListingVariant.php src/Exceptions/ListingException.php src/Actions/ConfirmListing.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Listing/ConfirmListingTest.php
git commit -m "feat: validate and confirm listings before import"
```

---

### Task 9: Confirmation page

**Files:**
- Create: `src/Filament/Resources/CandidateResource/Pages/ConfirmListing.php`
- Create: `resources/views/filament/pages/confirm-listing.blade.php`
- Modify: `src/Filament/Resources/CandidateResource.php` (page route + `confirm` action)
- Modify: `lang/en/admin.php`, `lang/pt_BR/admin.php`
- Test: `tests/Filament/ConfirmListingPageTest.php`
- Test: `tests/Filament/CandidateResourceTest.php` (confirm action)

**Interfaces:**
- Consumes: `FreightQuoter::quote()` + `commonMethods()` (Task 5), `ListingPriceCalculator` (Task 4), `Listing`/`ListingVariant`/`ListingException`/`Actions\ConfirmListing` (Task 8), `VariantWriter::costFor()`, `CostParser::lowest()`, SDK `Variant::$suggestedSellPrice`.
- Produces: page class `Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource\Pages\ConfirmListing` at route `/{record}/confirm` (resource page name `confirm`). Livewire state:
  - `array $data` — form (`name, ship_from_country, ship_to_country, currency_code, shipping_method, markup_percent, rounding, product_type_id, brand_id, collection_id`)
  - `list<array{vid: string, sku: string|null, label: string, image: string|null, weight: string|null, cost_usd: string|null, suggested_usd: string|null, selected: bool, price: string|null, methods: array<string, array{price_usd: string|null, aging: string|null}>}> $variants`
  - `array<string, string> $shipFromOptions`, `string $bulkMode` (`percent|amount|set`), `?string $bulkValue`, `bool $acceptNegativeMargin`, `?string $loadError`
  - methods `quoteShipping()`, `recommendPrices()`, `applyBulk()`, `listNow()`, view helpers `shippingFor(int)`, `totalFor(int)`, `rrpFor(int)`, `marginFor(int)`, `minimumMargin()`.
  - The page class is the Filament page named `ConfirmListing`; the domain action is imported as `use Thayron\LunarCjDropshipping\Actions\ConfirmListing as ConfirmListingAction;`.

- [ ] **Step 1: Write the failing page test**

`tests/Filament/ConfirmListingPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
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
}
```

Notes for the implementer:
- The "validation errors" test never quotes shipping, so no method is chosen and `ConfirmListing` fails on `method_required` (checked before the variant loop). Do not fill `shipping_method` in that test: a Filament `Select` rejects values that are not among its options.
- `Country::factory()` may require extra columns; pass whatever the factory needs if it fails.
- Expected recommendation math (GBP rate 0.787037037036 per USD): v-1 (10.00 + 5.43) × 2 → 24.29 → ends_99 `24.99`; v-2 (8.13 + 2.10) × 2 → 16.10 → `16.99`.

- [ ] **Step 2: Add the confirm action test**

In `tests/Filament/CandidateResourceTest.php` add:

```php
    public function test_confirm_action_opens_the_confirmation_page(): void
    {
        $pending = $this->candidate('p-1', CandidateStatus::Pending);

        Livewire::test(ListCandidates::class)
            ->assertTableActionVisible('confirm', $pending)
            ->assertTableActionHasUrl('confirm', CandidateResource::getUrl('confirm', ['record' => $pending]), $pending);
    }
```

(with `use Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource;`)

- [ ] **Step 3: Run and see both fail**

Run: `vendor/bin/phpunit tests/Filament/ConfirmListingPageTest.php tests/Filament/CandidateResourceTest.php`
Expected: FAIL — page class and `confirm` action missing.

- [ ] **Step 4: Register page and action in `CandidateResource`**

In `getDefaultPages()`:

```php
        return [
            'index' => Pages\ListCandidates::route('/'),
            'confirm' => Pages\ConfirmListing::route('/{record}/confirm'),
        ];
```

First entry in `->actions([...])`:

```php
                Tables\Actions\Action::make('confirm')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.confirm'))
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (Candidate $record): bool => in_array($record->status, [CandidateStatus::Pending, CandidateStatus::Failed], true))
                    ->url(fn (Candidate $record): string => static::getUrl('confirm', ['record' => $record])),
```

- [ ] **Step 5: Create the page class**

`src/Filament/Resources/CandidateResource/Pages/ConfirmListing.php`:

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Lunar\Models\Brand;
use Lunar\Models\Collection;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\ProductType;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Data\FreightOption;
use Thayron\CjDropshipping\Data\ProductInventory;
use Thayron\CjDropshipping\Exceptions\NotFoundException;
use Thayron\CjDropshipping\Exceptions\QuotaExceededException;
use Thayron\LunarCjDropshipping\Actions\ConfirmListing as ConfirmListingAction;
use Thayron\LunarCjDropshipping\Catalog\VariantWriter;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\ListingException;
use Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource;
use Thayron\LunarCjDropshipping\Listing\Listing;
use Thayron\LunarCjDropshipping\Listing\ListingVariant;
use Thayron\LunarCjDropshipping\Logistics\FreightQuoter;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Pricing\CostParser;
use Thayron\LunarCjDropshipping\Pricing\ListingPriceCalculator;
use Thayron\LunarCjDropshipping\Support\QuotaDelay;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Throwable;

class ConfirmListing extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    private const SCALE = 12;

    protected static string $resource = CandidateResource::class;

    protected static string $view = 'lunar-cjdropshipping::filament.pages.confirm-listing';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var list<array{vid: string, sku: string|null, label: string, image: string|null, weight: string|null, cost_usd: string|null, suggested_usd: string|null, selected: bool, price: string|null, methods: array<string, array{price_usd: string|null, aging: string|null}>}> */
    public array $variants = [];

    /** @var array<string, string> */
    public array $shipFromOptions = [];

    public string $bulkMode = 'percent';

    public ?string $bulkValue = null;

    public bool $acceptNegativeMargin = false;

    public ?string $loadError = null;

    public function getTitle(): string
    {
        return __('lunar-cjdropshipping::admin.listing.title');
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $candidate = $this->candidate();
        $listing = is_array($candidate->listing) ? Listing::fromArray($candidate->listing) : null;

        try {
            $throttle = app(Throttle::class);
            $cj = app(CjClient::class);
            $throttle->wait();
            $product = $cj->products()->find($candidate->cj_product_id);
            $throttle->wait();
            $inventory = $cj->products()->inventoryByProduct($candidate->cj_product_id);
        } catch (NotFoundException) {
            $candidate->forceFill(['status' => CandidateStatus::Unavailable])->save();
            $this->loadError = __('lunar-cjdropshipping::admin.listing.unavailable');
            $this->form->fill();

            return;
        } catch (Throwable $exception) {
            $this->loadError = __('lunar-cjdropshipping::admin.listing.load_failed', ['message' => $exception->getMessage()]);
            $this->form->fill();

            return;
        }

        $this->shipFromOptions = $this->stockCountries($inventory);

        /** @var array<string, ListingVariant> $listed */
        $listed = [];

        foreach ($listing?->variants ?? [] as $listedVariant) {
            $listed[$listedVariant->vid] = $listedVariant;
        }

        foreach ($product->variants as $variant) {
            $this->variants[] = [
                'vid' => $variant->id,
                'sku' => $variant->sku,
                'label' => $variant->key ?? $variant->name ?? $variant->id,
                'image' => $variant->image,
                'weight' => $variant->weight,
                'cost_usd' => app(VariantWriter::class)->costFor($product, $variant),
                'suggested_usd' => CostParser::lowest($variant->suggestedSellPrice),
                'selected' => $listing === null ? true : ($listed[$variant->id]->selected ?? false),
                'price' => $listed[$variant->id]->price ?? null,
                'methods' => [],
            ];
        }

        $rule = $candidate->importRule;

        $this->form->fill($listing !== null ? [
            'name' => $listing->name,
            'ship_from_country' => $listing->shipFromCountry,
            'ship_to_country' => $listing->shipToCountry,
            'currency_code' => $listing->currencyCode,
            'shipping_method' => null,
            'markup_percent' => $listing->markupPercent,
            'rounding' => $listing->rounding->value,
            'product_type_id' => $listing->productTypeId,
            'brand_id' => $listing->brandId,
            'collection_id' => $listing->collectionId,
        ] : [
            'name' => $candidate->name,
            'ship_from_country' => array_key_first($this->shipFromOptions),
            'ship_to_country' => null,
            'currency_code' => Currency::getDefault()?->code,
            'shipping_method' => null,
            'markup_percent' => $rule !== null ? (string) $rule->markup_percent : '100',
            'rounding' => $rule !== null ? $rule->rounding->value : PriceRounding::Ends99->value,
            'product_type_id' => $rule?->product_type_id ?? ProductType::query()->value('id'),
            'brand_id' => $rule?->brand_id,
            'collection_id' => $rule?->collection_id,
        ]);
    }

    public function form(Form $form): Form
    {
        $label = fn (string $key): string => __("lunar-cjdropshipping::admin.listing.fields.{$key}");

        return $form
            ->statePath('data')
            ->columns(3)
            ->schema([
                TextInput::make('name')->label($label('name'))->required()->maxLength(255)->columnSpanFull(),
                Select::make('ship_from_country')->label($label('ship_from'))
                    ->options(fn (): array => $this->shipFromOptions)
                    ->required()->live()->afterStateUpdated(fn () => $this->clearQuote()),
                Select::make('ship_to_country')->label($label('ship_to'))
                    ->options(fn (): array => Country::query()->orderBy('name')->pluck('name', 'iso2')->all())
                    ->searchable()->required()->live()->afterStateUpdated(fn () => $this->clearQuote()),
                Select::make('currency_code')->label($label('currency'))
                    ->options(fn (): array => Currency::query()->where('enabled', true)->orderBy('code')->pluck('name', 'code')->all())
                    ->required()->live(),
                Select::make('shipping_method')->label($label('method'))
                    ->options(fn (): array => $this->methodOptions())
                    ->live(),
                TextInput::make('markup_percent')->label($label('markup'))->numeric()->minValue(0)->suffix('%'),
                Select::make('rounding')->label($label('rounding'))
                    ->options(collect(PriceRounding::cases())->mapWithKeys(fn (PriceRounding $rounding): array => [$rounding->value => __('lunar-cjdropshipping::admin.rounding.'.$rounding->value)])->all()),
                Select::make('product_type_id')->label($label('product_type'))
                    ->options(fn (): array => ProductType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                Select::make('brand_id')->label($label('brand'))
                    ->options(fn (): array => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('collection_id')->label($label('collection'))
                    ->options(fn (): array => Collection::query()->get()->mapWithKeys(fn (Collection $collection): array => [$collection->id => (string) $collection->translateAttribute('name')])->all())
                    ->searchable(),
            ]);
    }

    public function quoteShipping(FreightQuoter $quoter): void
    {
        $from = (string) ($this->data['ship_from_country'] ?? '');
        $to = (string) ($this->data['ship_to_country'] ?? '');

        if ($from === '' || $to === '') {
            Notification::make()->title(__('lunar-cjdropshipping::admin.listing.errors.countries_required'))->danger()->send();

            return;
        }

        $weights = [];

        foreach ($this->variants as $row) {
            $weights[$row['vid']] = $row['weight'];
        }

        try {
            $quote = $quoter->quote($this->candidate()->cj_product_id, $from, $to, $weights);
        } catch (QuotaExceededException) {
            Notification::make()->title(__('lunar-cjdropshipping::admin.listing.quota', ['minutes' => (int) ceil(QuotaDelay::seconds() / 60)]))->danger()->send();

            return;
        } catch (Throwable $exception) {
            Notification::make()->title(__('lunar-cjdropshipping::admin.listing.quote_failed', ['message' => $exception->getMessage()]))->danger()->send();

            return;
        }

        foreach ($this->variants as $index => $row) {
            $this->variants[$index]['methods'] = array_map(
                fn (FreightOption $option): array => ['price_usd' => $option->priceUsd, 'aging' => $option->aging],
                $quote[$row['vid']] ?? [],
            );
        }

        $methods = array_keys($this->methodOptions());

        if (! in_array($this->data['shipping_method'] ?? null, $methods, true)) {
            $this->data['shipping_method'] = $methods[0] ?? null;
        }
    }

    public function recommendPrices(ListingPriceCalculator $calculator): void
    {
        $currency = $this->currency();

        if ($currency === null) {
            Notification::make()->title(__('lunar-cjdropshipping::admin.listing.errors.currency_invalid'))->danger()->send();

            return;
        }

        $markup = is_numeric($this->data['markup_percent'] ?? null) ? (string) $this->data['markup_percent'] : '0';
        $rounding = PriceRounding::tryFrom((string) ($this->data['rounding'] ?? '')) ?? PriceRounding::None;

        foreach ($this->variants as $index => $row) {
            $shipping = $this->shippingFor($index);

            if (! $row['selected'] || $row['cost_usd'] === null || $shipping === null) {
                continue;
            }

            $this->variants[$index]['price'] = $calculator->recommend($row['cost_usd'], $shipping, $markup, $rounding, $currency);
        }
    }

    public function applyBulk(): void
    {
        $value = $this->bulkValue;
        $currency = $this->currency();

        if ($value === null || ! is_numeric($value) || $currency === null) {
            return;
        }

        $decimals = (int) $currency->decimal_places;

        foreach ($this->variants as $index => $row) {
            if (! $row['selected']) {
                continue;
            }

            $price = is_numeric($row['price']) ? (string) $row['price'] : null;

            $next = match ($this->bulkMode) {
                'percent' => $price === null ? null : bcmul($price, bcadd('1', bcdiv((string) $value, '100', self::SCALE), self::SCALE), self::SCALE),
                'amount' => $price === null ? null : bcadd($price, (string) $value, self::SCALE),
                default => (string) $value,
            };

            if ($next === null) {
                continue;
            }

            $this->variants[$index]['price'] = bccomp($next, '0', self::SCALE) < 0
                ? bcadd('0', '0', $decimals)
                : ListingPriceCalculator::roundHalfUp($next, $decimals);
        }
    }

    public function listNow(ConfirmListingAction $confirm): void
    {
        $this->form->validate();

        try {
            $confirm->handle($this->candidate(), $this->buildListing(), $this->acceptNegativeMargin);
        } catch (ListingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('lunar-cjdropshipping::admin.listing.listed'))->success()->send();
        $this->redirect(CandidateResource::getUrl('index'));
    }

    public function shippingFor(int $index): ?string
    {
        $method = (string) ($this->data['shipping_method'] ?? '');

        return $this->variants[$index]['methods'][$method]['price_usd'] ?? null;
    }

    public function totalFor(int $index): ?string
    {
        $cost = $this->variants[$index]['cost_usd'] ?? null;
        $shipping = $this->shippingFor($index);

        return $cost === null || $shipping === null ? null : bcadd($cost, $shipping, 2);
    }

    public function rrpFor(int $index): ?string
    {
        $suggested = $this->variants[$index]['suggested_usd'] ?? null;
        $currency = $this->currency();

        if ($suggested === null || $currency === null) {
            return null;
        }

        return app(ListingPriceCalculator::class)->inCurrency($suggested, $currency).' '.$currency->code;
    }

    public function marginFor(int $index): ?string
    {
        $row = $this->variants[$index] ?? null;
        $shipping = $this->shippingFor($index);
        $currency = $this->currency();

        if ($row === null || $row['cost_usd'] === null || $shipping === null || $currency === null || ! is_numeric($row['price'])) {
            return null;
        }

        return app(ListingPriceCalculator::class)->margin((string) $row['price'], $row['cost_usd'], $shipping, $currency);
    }

    public function minimumMargin(): string
    {
        return (string) config('lunar-cjdropshipping.pricing.min_margin_percent', 20);
    }

    private function candidate(): Candidate
    {
        /** @var Candidate $record */
        $record = $this->getRecord();

        return $record;
    }

    private function currency(): ?Currency
    {
        $code = (string) ($this->data['currency_code'] ?? '');

        return $code === '' ? null : Currency::query()->where('code', $code)->where('enabled', true)->first();
    }

    private function clearQuote(): void
    {
        foreach (array_keys($this->variants) as $index) {
            $this->variants[$index]['methods'] = [];
        }

        $this->data['shipping_method'] = null;
    }

    /**
     * @return array<string, string> method name => "name — US$ price — aging days"
     */
    private function methodOptions(): array
    {
        $options = [];

        foreach ($this->variants as $row) {
            foreach ($row['methods'] as $name => $method) {
                $options[(string) $name] ??= __('lunar-cjdropshipping::admin.listing.method_option', [
                    'name' => $name,
                    'price' => $method['price_usd'] ?? '—',
                    'aging' => $method['aging'] ?? '—',
                ]);
            }
        }

        return $options;
    }

    /**
     * @return array<string, string> country code => name, only countries with stock
     */
    private function stockCountries(ProductInventory $inventory): array
    {
        $codes = [];

        foreach ([$inventory->warehouses, ...array_values($inventory->variants)] as $stocks) {
            foreach ($stocks as $stock) {
                if ($stock->countryCode !== null && $stock->total > 0) {
                    $codes[strtoupper($stock->countryCode)] = true;
                }
            }
        }

        $codes = array_keys($codes);
        sort($codes);
        $names = Country::query()->whereIn('iso2', $codes)->pluck('name', 'iso2')->all();

        $options = [];

        foreach ($codes as $code) {
            $options[$code] = sprintf('%s (%s)', $names[$code] ?? $code, $code);
        }

        return $options;
    }

    private function buildListing(): Listing
    {
        $method = (string) ($this->data['shipping_method'] ?? '');

        return new Listing(
            name: trim((string) ($this->data['name'] ?? '')),
            shipFromCountry: strtoupper((string) ($this->data['ship_from_country'] ?? '')),
            shipToCountry: strtoupper((string) ($this->data['ship_to_country'] ?? '')),
            currencyCode: strtoupper((string) ($this->data['currency_code'] ?? '')),
            shippingMethod: $method,
            markupPercent: is_numeric($this->data['markup_percent'] ?? null) ? (string) $this->data['markup_percent'] : '0',
            rounding: PriceRounding::tryFrom((string) ($this->data['rounding'] ?? '')) ?? PriceRounding::None,
            productTypeId: (int) ($this->data['product_type_id'] ?? 0),
            brandId: filled($this->data['brand_id'] ?? null) ? (int) $this->data['brand_id'] : null,
            collectionId: filled($this->data['collection_id'] ?? null) ? (int) $this->data['collection_id'] : null,
            variants: array_map(
                fn (array $row): ListingVariant => new ListingVariant(
                    $row['vid'],
                    (bool) $row['selected'],
                    $row['cost_usd'],
                    $row['methods'][$method]['price_usd'] ?? null,
                    is_numeric($row['price']) ? (string) $row['price'] : null,
                ),
                $this->variants,
            ),
        );
    }
}
```

Implementer notes:
- `listNow` runs `$this->form->validate()` first, so the "validation errors" test needs `name`, `ship_from_country`, `ship_to_country`, `currency_code` and `product_type_id` filled (defaults come from `mount`); `shipping_method` is not `required()` in the form because the action gives a clearer message.
- If `assertSet('variants.0.methods.CJPacket Ordinary.price_usd', ...)` cannot resolve the key with a space, assert `->assertSet('variants.0.methods', ['CJPacket Ordinary' => ['price_usd' => '5.43', 'aging' => '7-12']])` instead.

- [ ] **Step 6: Create `resources/views/filament/pages/confirm-listing.blade.php`**

```blade
<x-filament-panels::page>
    @if ($loadError)
        <x-filament::section>
            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $loadError }}</p>
        </x-filament::section>
    @else
        <form wire:submit="listNow" class="space-y-6">
            {{ $this->form }}

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="button" color="gray" icon="heroicon-o-truck" wire:click="quoteShipping">
                    {{ __('lunar-cjdropshipping::admin.listing.actions.quote') }}
                </x-filament::button>

                <x-filament::button type="button" icon="heroicon-o-sparkles" wire:click="recommendPrices">
                    {{ __('lunar-cjdropshipping::admin.listing.actions.recommend') }}
                </x-filament::button>

                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="bulkMode">
                        <option value="percent">{{ __('lunar-cjdropshipping::admin.listing.bulk.percent') }}</option>
                        <option value="amount">{{ __('lunar-cjdropshipping::admin.listing.bulk.amount') }}</option>
                        <option value="set">{{ __('lunar-cjdropshipping::admin.listing.bulk.set') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <x-filament::input.wrapper>
                    <x-filament::input type="number" step="0.01" wire:model="bulkValue" />
                </x-filament::input.wrapper>

                <x-filament::button type="button" color="gray" wire:click="applyBulk">
                    {{ __('lunar-cjdropshipping::admin.listing.bulk.apply') }}
                </x-filament::button>
            </div>

            <div style="overflow-x:auto" class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            @foreach (['selected', 'image', 'sku', 'variant', 'cost', 'shipping', 'total', 'rrp', 'price', 'margin'] as $column)
                                <th class="px-3 py-2 text-start font-medium">{{ __('lunar-cjdropshipping::admin.listing.columns.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($variants as $index => $row)
                            @php
                                $shipping = $this->shippingFor($index);
                                $total = $this->totalFor($index);
                                $margin = $this->marginFor($index);
                                $belowMinimum = $margin !== null && bccomp($margin, $this->minimumMargin(), 2) < 0;
                            @endphp

                            <tr wire:key="variant-{{ $row['vid'] }}" class="border-t border-gray-200 dark:border-white/5">
                                <td class="px-3 py-2">
                                    <x-filament::input.checkbox wire:model.live="variants.{{ $index }}.selected" />
                                </td>
                                <td class="px-3 py-2">
                                    @if ($row['image'])
                                        <img src="{{ $row['image'] }}" alt="" loading="lazy" style="width:3rem;height:3rem;object-fit:cover;border-radius:0.375rem">
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $row['sku'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['label'] }}</td>
                                <td class="px-3 py-2">{{ $row['cost_usd'] !== null ? 'US$ '.$row['cost_usd'] : '—' }}</td>
                                <td class="px-3 py-2">{{ $shipping !== null ? 'US$ '.$shipping : '—' }}</td>
                                <td class="px-3 py-2">{{ $total !== null ? 'US$ '.$total : '—' }}</td>
                                <td class="px-3 py-2">{{ $this->rrpFor($index) ?? '—' }}</td>
                                <td class="px-3 py-2" style="min-width:7rem">
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="number" step="0.01" min="0" wire:model.live.debounce.500ms="variants.{{ $index }}.price" />
                                    </x-filament::input.wrapper>
                                </td>
                                <td class="px-3 py-2 font-medium {{ $belowMinimum ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                                    {{ $margin !== null ? $margin.'%' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <x-filament::input.checkbox wire:model="acceptNegativeMargin" />
                {{ __('lunar-cjdropshipping::admin.listing.accept_negative_margin') }}
            </label>

            <x-filament::button type="submit" icon="heroicon-o-check">
                {{ __('lunar-cjdropshipping::admin.listing.actions.list_now') }}
            </x-filament::button>
        </form>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 7: Translations**

`lang/en/admin.php`: add `candidates.actions.confirm` = `'Confirm'`; add inside `listing` (next to `errors`):

```php
        'title' => 'Confirm listing',
        'unavailable' => 'This product is no longer available on CJdropshipping.',
        'load_failed' => 'Could not load the product from CJ: :message',
        'quote_failed' => 'Shipping quote failed, try again: :message',
        'quota' => 'The CJ daily quota is used up. Try again in about :minutes minutes.',
        'listed' => 'Sent for import.',
        'method_option' => ':name — US$ :price — :aging days',
        'accept_negative_margin' => 'I accept a negative margin',
        'fields' => [
            'name' => 'Product name',
            'ship_from' => 'Ship from',
            'ship_to' => 'Ship to',
            'currency' => 'Currency',
            'method' => 'Shipping method',
            'markup' => 'Markup',
            'rounding' => 'Price rounding',
            'product_type' => 'Product type',
            'brand' => 'Brand',
            'collection' => 'Collection',
        ],
        'columns' => [
            'selected' => 'Import',
            'image' => 'Image',
            'sku' => 'SKU',
            'variant' => 'Variant',
            'cost' => 'CJ cost',
            'shipping' => 'Shipping',
            'total' => 'Total cost',
            'rrp' => 'CJ RRP',
            'price' => 'Your price',
            'margin' => 'Margin',
        ],
        'bulk' => [
            'percent' => 'Adjust by %',
            'amount' => 'Adjust by amount',
            'set' => 'Set price',
            'apply' => 'Apply to selected',
        ],
        'actions' => [
            'quote' => 'Quote shipping',
            'recommend' => 'Recommend price',
            'list_now' => 'List it now',
        ],
```

`lang/pt_BR/admin.php`: add `candidates.actions.confirm` = `'Confirmar'`; add inside `listing`:

```php
        'title' => 'Confirmar listagem',
        'unavailable' => 'Este produto não está mais disponível na CJdropshipping.',
        'load_failed' => 'Não foi possível carregar o produto da CJ: :message',
        'quote_failed' => 'A cotação de frete falhou, tente de novo: :message',
        'quota' => 'A cota diária da CJ acabou. Tente de novo em cerca de :minutes minutos.',
        'listed' => 'Enviado para importação.',
        'method_option' => ':name — US$ :price — :aging dias',
        'accept_negative_margin' => 'Aceito margem negativa',
        'fields' => [
            'name' => 'Nome do produto',
            'ship_from' => 'Enviar de',
            'ship_to' => 'Enviar para',
            'currency' => 'Moeda',
            'method' => 'Método de envio',
            'markup' => 'Markup',
            'rounding' => 'Arredondamento',
            'product_type' => 'Tipo de produto',
            'brand' => 'Marca',
            'collection' => 'Coleção',
        ],
        'columns' => [
            'selected' => 'Importar',
            'image' => 'Imagem',
            'sku' => 'SKU',
            'variant' => 'Variação',
            'cost' => 'Custo CJ',
            'shipping' => 'Frete',
            'total' => 'Custo total',
            'rrp' => 'Preço sugerido CJ',
            'price' => 'Seu preço',
            'margin' => 'Margem',
        ],
        'bulk' => [
            'percent' => 'Ajustar em %',
            'amount' => 'Ajustar em valor',
            'set' => 'Definir preço',
            'apply' => 'Aplicar aos selecionados',
        ],
        'actions' => [
            'quote' => 'Cotar frete',
            'recommend' => 'Recomendar preço',
            'list_now' => 'Listar agora',
        ],
```

- [ ] **Step 8: Run tests and analysis**

Run: `vendor/bin/phpunit` then `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: green.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint src/Filament/Resources/CandidateResource/Pages/ConfirmListing.php src/Filament/Resources/CandidateResource.php lang/en/admin.php lang/pt_BR/admin.php tests/Filament/ConfirmListingPageTest.php tests/Filament/CandidateResourceTest.php
git add src/Filament/Resources/CandidateResource/Pages/ConfirmListing.php resources/views/filament/pages/confirm-listing.blade.php src/Filament/Resources/CandidateResource.php lang/en/admin.php lang/pt_BR/admin.php tests/Filament/ConfirmListingPageTest.php tests/Filament/CandidateResourceTest.php
git commit -m "feat: confirm listing page with freight quote and per-variant pricing"
```

---

### Task 10: Import a confirmed listing

**Files:**
- Modify: `src/Actions/ImportProduct.php`
- Modify: `src/Catalog/VariantWriter.php`
- Test: `tests/Feature/Import/ImportProductTest.php` (add tests)

**Interfaces:**
- Consumes: `Listing`, `ListingVariant` (Task 8), link/variant-link columns (Task 3).
- Produces:
  - `VariantWriter::create(Product $product, ProductLink $link, CjProduct $cjProduct, CjVariant $cjVariant, array $values, array $options, ProductInventory $inventory, ?ListingVariant $listed = null): VariantLink` — when `$listed` is given, writes ONE price row in `$link->currency_code` from `$listed->price` and stores `shipping_cost_usd` and `price` on the variant link.
  - `VariantWriter::writeListedPrice(ProductVariant $variant, string $price, Currency $currency): void`.
  - `ImportProduct::handle(Candidate $candidate): ImportResult` — unchanged signature; uses the listing when `$candidate->listing` is set, otherwise the rule (legacy path, unchanged behaviour).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Import/ImportProductTest.php` (imports: `Thayron\LunarCjDropshipping\Enums\CandidateSource`, `Thayron\LunarCjDropshipping\Listing\Listing`, `Thayron\LunarCjDropshipping\Listing\ListingVariant`):

```php
    public function test_imports_only_the_selected_variants_with_confirmed_prices(): void
    {
        $this->createLunarBaseline();
        $candidate = $this->listedCandidate([
            new ListingVariant('v-1', true, '10.00', '5.43', '24.99'),
            new ListingVariant('v-2', false, '8.13', '2.10', null),
        ]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        $result = app(ImportProduct::class)->handle($candidate);

        $product = Product::query()->sole();
        $this->assertSame('draft', $product->status);
        $this->assertSame('Plaid Dog Jacket', $product->translateAttribute('name', 'en'));
        $this->assertSame(1, $product->variants()->count());

        $black = ProductVariant::query()->where('sku', 'CJ-CASE-BLK-XL')->sole();
        $this->assertSame(['GBP' => 2499], $this->prices($black));
        $this->assertSame(100, (int) $black->stock);

        $link = $result->link->fresh();
        $this->assertNull($link->import_rule_id);
        $this->assertTrue($link->price_locked);
        $this->assertSame('CN', $link->country_code);
        $this->assertSame('CN', $link->ship_from_country);
        $this->assertSame('GB', $link->ship_to_country);
        $this->assertSame('CJPacket Ordinary', $link->shipping_method);
        $this->assertSame('GBP', $link->currency_code);
        $this->assertSame('100.00', $link->markup_percent);
        $this->assertSame(PriceRounding::Ends99, $link->rounding);
        $this->assertSame(['v-2'], $link->skipped_cj_variant_ids);

        $variantLink = VariantLink::query()->where('cj_variant_id', 'v-1')->sole();
        $this->assertSame('5.43', $variantLink->shipping_cost_usd);
        $this->assertSame('24.99', $variantLink->price);
        $this->assertSame(0, VariantLink::query()->where('cj_variant_id', 'v-2')->count());

        $this->assertSame(CandidateStatus::Imported, $candidate->fresh()->status);
    }

    public function test_fails_when_a_selected_variant_is_gone_from_cj(): void
    {
        $this->createLunarBaseline();
        $candidate = $this->listedCandidate([new ListingVariant('v-404', true, '10.00', '5.43', '24.99')]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('no longer available');

        try {
            app(ImportProduct::class)->handle($candidate);
        } finally {
            $this->assertSame(0, Product::withTrashed()->count());
        }
    }

    /**
     * @param  list<ListingVariant>  $variants
     */
    private function listedCandidate(array $variants): Candidate
    {
        return Candidate::create([
            'cj_product_id' => 'p-100',
            'source' => CandidateSource::Catalog,
            'name' => 'Plaid Dog Jacket',
            'status' => CandidateStatus::Approved,
            'payload' => [],
            'discovered_at' => now(),
            'listing' => (new Listing(
                name: 'Plaid Dog Jacket',
                shipFromCountry: 'CN',
                shipToCountry: 'GB',
                currencyCode: 'GBP',
                shippingMethod: 'CJPacket Ordinary',
                markupPercent: '100.00',
                rounding: PriceRounding::Ends99,
                productTypeId: $this->productType->id,
                brandId: null,
                collectionId: null,
                variants: $variants,
            ))->toArray(),
        ]);
    }
```

- [ ] **Step 2: Run and see them fail**

Run: `vendor/bin/phpunit tests/Feature/Import/ImportProductTest.php`
Expected: the two new tests FAIL ("has no import rule"); existing tests pass.

- [ ] **Step 3: Add listed-price support to `src/Catalog/VariantWriter.php`**

Imports: `use Lunar\Models\Currency;`, `use Thayron\LunarCjDropshipping\Listing\ListingVariant;`

Change the `create()` signature to add `?ListingVariant $listed = null` as the last parameter (PHPDoc: `@param  ListingVariant|null  $listed  confirmed listing prices; null prices from the link markup`), and replace

```php
        $cost = $this->costFor($cjProduct, $cjVariant) ?? throw new ImportException("CJ variant {$cjVariant->id} has no price.");
        $this->writePrices($variant, $cost, $link);

        return VariantLink::create([
            'cj_variant_id' => $cjVariant->id,
            'cj_product_link_id' => $link->id,
            'lunar_variant_id' => $variant->id,
            'cj_sku' => $cjVariant->sku,
            'cost_usd' => $cost,
            'stock' => $stock,
            'last_synced_at' => now(),
        ]);
```

with

```php
        $cost = $this->costFor($cjProduct, $cjVariant) ?? throw new ImportException("CJ variant {$cjVariant->id} has no price.");

        if ($listed !== null) {
            $currency = Currency::query()->where('code', (string) $link->currency_code)->first()
                ?? throw new ImportException("Lunar has no {$link->currency_code} currency.");
            $price = $listed->price ?? throw new ImportException("CJ variant {$cjVariant->id} has no confirmed price.");
            $this->writeListedPrice($variant, $price, $currency);
        } else {
            $this->writePrices($variant, $cost, $link);
        }

        return VariantLink::create([
            'cj_variant_id' => $cjVariant->id,
            'cj_product_link_id' => $link->id,
            'lunar_variant_id' => $variant->id,
            'cj_sku' => $cjVariant->sku,
            'cost_usd' => $cost,
            'shipping_cost_usd' => $listed?->shippingCostUsd,
            'price' => $listed?->price,
            'stock' => $stock,
            'last_synced_at' => now(),
        ]);
```

Add the method after `writePrices()`:

```php
    public function writeListedPrice(ProductVariant $variant, string $price, Currency $currency): void
    {
        $minor = (int) bcadd(bcmul($price, bcpow('10', (string) (int) $currency->decimal_places), 12), '0.5', 0);

        Price::query()->updateOrCreate([
            'priceable_type' => $variant->getMorphClass(),
            'priceable_id' => $variant->id,
            'currency_id' => $currency->id,
            'customer_group_id' => null,
            'min_quantity' => 1,
        ], ['price' => $minor]);
    }
```

- [ ] **Step 4: Use the listing in `src/Actions/ImportProduct.php`**

Imports: `use Thayron\CjDropshipping\Data\Variant as CjVariant;`, `use Thayron\LunarCjDropshipping\Listing\Listing;`, `use Thayron\LunarCjDropshipping\Listing\ListingVariant;`

In `handle()` replace the two lines added in Task 3:

```php
        $rule = $candidate->importRule ?? throw new ImportException("Candidate {$candidate->cj_product_id} has no import rule.");
        $link = DB::transaction(fn (): ProductLink => $this->createProduct($rule, $cjProduct, $inventory));
```

with

```php
        $listing = is_array($candidate->listing) ? Listing::fromArray($candidate->listing) : null;
        $link = DB::transaction(fn (): ProductLink => $this->createProduct($candidate, $listing, $cjProduct, $inventory));
```

Replace the whole `createProduct()` method with:

```php
    private function createProduct(Candidate $candidate, ?Listing $listing, CjProduct $cjProduct, ProductInventory $inventory): ProductLink
    {
        $settings = $this->settings($candidate, $listing, $cjProduct);

        /** @var array<string, ListingVariant> $selected */
        $selected = [];

        foreach ($listing?->selectedVariants() ?? [] as $listedVariant) {
            $selected[$listedVariant->vid] = $listedVariant;
        }

        $variants = $listing === null
            ? $cjProduct->variants
            : array_values(array_filter($cjProduct->variants, fn (CjVariant $variant): bool => isset($selected[$variant->id])));

        if ($listing !== null && count($variants) !== count($selected)) {
            throw new ImportException("Some selected variants of CJ product {$cjProduct->id} are no longer available.");
        }

        if ($variants === []) {
            throw new ImportException("CJ product {$cjProduct->id} has no variants.");
        }

        $product = Product::create([
            'product_type_id' => $settings['product_type_id'],
            'brand_id' => $settings['brand_id'],
            'status' => 'draft',
            'attribute_data' => collect([
                'name' => $this->translatedText($settings['name']),
                'description' => $this->translatedText($cjProduct->description ?? ''),
            ]),
        ]);

        $product->scheduleChannel(Channel::getDefault());

        if ($settings['collection_id'] !== null) {
            $product->collections()->attach($settings['collection_id'], ['position' => 1]);
        }

        $link = ProductLink::create([
            'cj_product_id' => $cjProduct->id,
            'lunar_product_id' => $product->id,
            'import_rule_id' => $candidate->import_rule_id,
            'markup_percent' => $settings['markup_percent'],
            'rounding' => $settings['rounding'],
            'country_code' => $settings['country_code'],
            'ship_from_country' => $listing?->shipFromCountry,
            'ship_to_country' => $listing?->shipToCountry,
            'shipping_method' => $listing?->shippingMethod,
            'currency_code' => $listing?->currencyCode,
            'price_locked' => $listing !== null,
            'cj_status' => CjProductStatus::Active,
            'new_cj_variant_ids' => [],
            'skipped_cj_variant_ids' => $listing?->skippedVariantIds() ?? [],
            'last_synced_at' => now(),
        ]);

        $raw = $cjProduct->raw();
        $parsed = $this->parser->parse($raw['productKeyEn'] ?? null, $variants);
        $optionModels = array_map(fn (string $name) => $this->options->option($name), $parsed['options']);

        foreach ($optionModels as $position => $option) {
            $product->productOptions()->attach($option->id, ['position' => $position + 1]);
        }

        foreach ($variants as $cjVariant) {
            $this->variants->create($product, $link, $cjProduct, $cjVariant, $parsed['values'][$cjVariant->id] ?? [], $optionModels, $inventory, $selected[$cjVariant->id] ?? null);
        }

        return $link;
    }

    /**
     * @return array{name: string, product_type_id: int, brand_id: int|null, collection_id: int|null, markup_percent: string, rounding: PriceRounding, country_code: string|null}
     */
    private function settings(Candidate $candidate, ?Listing $listing, CjProduct $cjProduct): array
    {
        if ($listing !== null) {
            return [
                'name' => $listing->name,
                'product_type_id' => $listing->productTypeId,
                'brand_id' => $listing->brandId,
                'collection_id' => $listing->collectionId,
                'markup_percent' => $listing->markupPercent,
                'rounding' => $listing->rounding,
                'country_code' => $listing->shipFromCountry,
            ];
        }

        $rule = $candidate->importRule ?? throw new ImportException("Candidate {$candidate->cj_product_id} has no listing or import rule.");

        return [
            'name' => $cjProduct->name ?? $cjProduct->id,
            'product_type_id' => $rule->product_type_id,
            'brand_id' => $rule->brand_id,
            'collection_id' => $rule->collection_id,
            'markup_percent' => (string) $rule->markup_percent,
            'rounding' => $rule->rounding,
            'country_code' => $rule->country_code !== null ? strtoupper($rule->country_code) : null,
        ];
    }
```

Add `use Thayron\LunarCjDropshipping\Enums\PriceRounding;` and remove `use Thayron\LunarCjDropshipping\Models\ImportRule;` if it becomes unused. `settings()` runs inside the transaction, so a missing rule still rolls back cleanly.

- [ ] **Step 5: Run tests and analysis**

Run: `vendor/bin/phpunit` then `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: green, including all existing import, sync and new-variant tests.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint src/Actions/ImportProduct.php src/Catalog/VariantWriter.php tests/Feature/Import/ImportProductTest.php
git add src/Actions/ImportProduct.php src/Catalog/VariantWriter.php tests/Feature/Import/ImportProductTest.php
git commit -m "feat: import confirmed listings with selected variants and locked prices"
```

---

### Task 11: Sync keeps locked prices and flags margin at risk

**Files:**
- Modify: `src/Actions/SyncProduct.php`
- Modify: `src/Filament/Resources/ProductLinkResource.php`
- Modify: `lang/en/admin.php`, `lang/pt_BR/admin.php`
- Test: `tests/Feature/Sync/SyncLockedPriceTest.php`
- Test: `tests/Filament/ProductLinkMarginFilterTest.php`

**Interfaces:**
- Consumes: `ListingPriceCalculator::margin()` (Task 4), link columns (Task 3), config `pricing.min_margin_percent`.
- Produces: `SyncProduct::handle(ProductLink $link): void` (unchanged signature). For `price_locked` links it never writes prices and sets `margin_at_risk` = any linked variant whose margin (stored `price` vs new cost + stored `shipping_cost_usd`, in `currency_code`) is below the minimum or cannot be computed. `new_cj_variant_ids` excludes `skipped_cj_variant_ids` for every link.

- [ ] **Step 1: Write the failing sync test**

`tests/Feature/Sync/SyncLockedPriceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Sync;

use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Thayron\LunarCjDropshipping\Actions\SyncProduct;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\Support\ImportsFixtureProduct;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class SyncLockedPriceTest extends TestCase
{
    use CreatesLunarBaseline;
    use ImportsFixtureProduct;

    private FakeCj $cj;

    private ProductLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cjdropshipping.max_retries' => 0]);
        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
        $this->link = $this->importFixtureProduct();
        $this->link->forceFill(['price_locked' => true, 'currency_code' => 'GBP'])->save();
        VariantLink::query()->update(['shipping_cost_usd' => '5.00', 'price' => '30.00']);
    }

    public function test_keeps_locked_prices_when_cost_changes(): void
    {
        $before = $this->prices($this->variant('CJ-CASE-BLK-XL'));
        $this->syncWithCost('12.00');

        $this->assertSame($before, $this->prices($this->variant('CJ-CASE-BLK-XL')));
        $this->assertSame('12.00', VariantLink::query()->where('cj_variant_id', 'v-1')->value('cost_usd'));
        $this->assertFalse($this->link->fresh()->margin_at_risk);
    }

    public function test_flags_and_clears_margin_at_risk(): void
    {
        // (30.00 + 5.00) USD = 27.55 GBP against a 30.00 GBP price: 8.18% margin, below 20%.
        $this->syncWithCost('30.00');
        $this->assertTrue($this->link->fresh()->margin_at_risk);

        $this->syncWithCost('12.00');
        $this->assertFalse($this->link->fresh()->margin_at_risk);
    }

    public function test_flags_links_whose_currency_is_missing(): void
    {
        $this->link->forceFill(['currency_code' => 'JPY'])->save();

        $this->syncWithCost('12.00');

        $this->assertTrue($this->link->fresh()->margin_at_risk);
    }

    public function test_skipped_variants_are_not_reported_as_new(): void
    {
        $this->link->forceFill(['skipped_cj_variant_ids' => ['v-9']])->save();
        $detail = FakeCj::data('product-detail');
        $detail['variants'][] = [...$detail['variants'][0], 'vid' => 'v-9', 'variantSku' => 'CJ-CASE-SKIPPED'];
        $detail['variants'][] = [...$detail['variants'][0], 'vid' => 'v-10', 'variantSku' => 'CJ-CASE-NEW'];
        $this->cj->success($detail)->fixture('stock-by-pid');

        app(SyncProduct::class)->handle($this->link->fresh());

        $this->assertSame(['v-10'], $this->link->fresh()->new_cj_variant_ids);
    }

    private function syncWithCost(string $cost): void
    {
        $detail = FakeCj::data('product-detail');
        $detail['variants'][0]['variantSellPrice'] = $cost;
        $this->cj->success($detail)->fixture('stock-by-pid');

        app(SyncProduct::class)->handle($this->link->fresh());
    }

    private function variant(string $sku): ProductVariant
    {
        return ProductVariant::query()->where('sku', $sku)->sole();
    }

    /**
     * @return array<string, int>
     */
    private function prices(ProductVariant $variant): array
    {
        return Price::query()
            ->where('priceable_type', $variant->getMorphClass())
            ->where('priceable_id', $variant->id)
            ->get()
            ->mapWithKeys(fn (Price $price) => [$price->currency->code => $price->price->value])
            ->sortKeys()
            ->all();
    }
}
```

- [ ] **Step 2: Run and see it fail**

Run: `vendor/bin/phpunit tests/Feature/Sync/SyncLockedPriceTest.php`
Expected: FAIL — prices recalculated, `margin_at_risk` never set, `v-9` reported as new.

- [ ] **Step 3: Update `src/Actions/SyncProduct.php`**

- Update the class docblock to: `Syncs stock, cost-based prices (unless locked by a confirmed listing) and availability. Never touches names, descriptions, images or options.`
- Imports: `use Thayron\LunarCjDropshipping\Models\VariantLink;`, `use Thayron\LunarCjDropshipping\Pricing\ListingPriceCalculator;`
- Constructor: add `private readonly ListingPriceCalculator $listingPrices,` as the last parameter.
- Inside the transaction closure, before the `foreach`:

```php
            $currency = $link->price_locked ? Currency::query()->where('code', (string) $link->currency_code)->first() : null;
            $minimumMargin = (string) config('lunar-cjdropshipping.pricing.min_margin_percent', 20);
            $marginAtRisk = false;
```

- Replace

```php
                if ($cost !== null && ($costChanged || $this->isMissingPrices($variant, $enabledCurrencyIds))) {
                    $this->variants->writePrices($variant, $cost, $link);
                }
```

with

```php
                if ($link->price_locked) {
                    $marginAtRisk = $marginAtRisk || $this->isMarginAtRisk($variantLink, $cost ?? $variantLink->cost_usd, $currency, $minimumMargin);
                } elseif ($cost !== null && ($costChanged || $this->isMissingPrices($variant, $enabledCurrencyIds))) {
                    $this->variants->writePrices($variant, $cost, $link);
                }
```

- In the final `forceFill`, replace the `new_cj_variant_ids` line and add `margin_at_risk`:

```php
                'new_cj_variant_ids' => array_values(array_diff(array_map('strval', array_keys($cjVariants)), $known, $link->skipped_cj_variant_ids ?? [])),
                'margin_at_risk' => $marginAtRisk,
```

- Add the method:

```php
    private function isMarginAtRisk(VariantLink $variantLink, ?string $costUsd, ?Currency $currency, string $minimumMargin): bool
    {
        if ($currency === null || $costUsd === null || $variantLink->price === null || $variantLink->shipping_cost_usd === null) {
            return true;
        }

        $margin = $this->listingPrices->margin((string) $variantLink->price, $costUsd, (string) $variantLink->shipping_cost_usd, $currency);

        return $margin === null || bccomp($margin, $minimumMargin, 2) < 0;
    }
```

- [ ] **Step 4: Run sync tests**

Run: `vendor/bin/phpunit tests/Feature/Sync`
Expected: green (existing `SyncProductTest` unchanged).

- [ ] **Step 5: Write the failing admin filter test**

`tests/Filament/ProductLinkMarginFilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Livewire\Livewire;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Filament\Resources\ProductLinkResource\Pages\ListProductLinks;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\FilamentTestCase;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;

final class ProductLinkMarginFilterTest extends FilamentTestCase
{
    use CreatesLunarBaseline;

    public function test_filters_links_with_margin_at_risk(): void
    {
        $this->createLunarBaseline();
        $this->actingAsStaff();
        $risky = $this->link('p-1', true);
        $healthy = $this->link('p-2', false);

        Livewire::test(ListProductLinks::class)
            ->assertCanSeeTableRecords([$risky, $healthy])
            ->assertSee(__('lunar-cjdropshipping::admin.links.margin.at_risk'))
            ->filterTable('margin_at_risk')
            ->assertCanSeeTableRecords([$risky])
            ->assertCanNotSeeTableRecords([$healthy]);
    }

    private function link(string $cjProductId, bool $atRisk): ProductLink
    {
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);

        return ProductLink::create([
            'cj_product_id' => $cjProductId, 'lunar_product_id' => $product->id, 'markup_percent' => '0',
            'rounding' => PriceRounding::None, 'new_cj_variant_ids' => [], 'price_locked' => true, 'margin_at_risk' => $atRisk,
        ]);
    }
}
```

Note: if `ProductLinkResource` defers loading, add `->loadTable()` after `Livewire::test(...)`.

- [ ] **Step 6: Run and see it fail**

Run: `vendor/bin/phpunit tests/Filament/ProductLinkMarginFilterTest.php`
Expected: FAIL — filter not found.

- [ ] **Step 7: Update `src/Filament/Resources/ProductLinkResource.php`**

Add after the `new_variants` column:

```php
                Tables\Columns\TextColumn::make('margin_at_risk')
                    ->label($column('margin'))
                    ->badge()
                    ->state(fn (ProductLink $record): ?string => $record->price_locked ? ($record->margin_at_risk ? 'at_risk' : 'ok') : null)
                    ->formatStateUsing(fn (string $state): string => __('lunar-cjdropshipping::admin.links.margin.'.$state))
                    ->color(fn (string $state): string => $state === 'at_risk' ? 'danger' : 'success')
                    ->placeholder('—'),
```

Add to `->filters([...])`:

```php
                Tables\Filters\Filter::make('margin_at_risk')
                    ->label(__('lunar-cjdropshipping::admin.links.filters.margin_at_risk'))
                    ->query(fn (Builder $query): Builder => $query->where('margin_at_risk', true)),
```

- [ ] **Step 8: Translations**

`lang/en/admin.php` → `links.columns.margin` = `'Margin'`, `links.filters.margin_at_risk` = `'Margin at risk'`, and in `links`:

```php
        'margin' => [
            'at_risk' => 'At risk',
            'ok' => 'OK',
        ],
```

`lang/pt_BR/admin.php` → `links.columns.margin` = `'Margem'`, `links.filters.margin_at_risk` = `'Margem em risco'`, and in `links`:

```php
        'margin' => [
            'at_risk' => 'Em risco',
            'ok' => 'OK',
        ],
```

- [ ] **Step 9: Run tests and analysis**

Run: `vendor/bin/phpunit` then `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: green.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint src/Actions/SyncProduct.php src/Filament/Resources/ProductLinkResource.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Sync/SyncLockedPriceTest.php tests/Filament/ProductLinkMarginFilterTest.php
git add src/Actions/SyncProduct.php src/Filament/Resources/ProductLinkResource.php lang/en/admin.php lang/pt_BR/admin.php tests/Feature/Sync/SyncLockedPriceTest.php tests/Filament/ProductLinkMarginFilterTest.php
git commit -m "feat: keep locked listing prices on sync and flag margin at risk"
```

---

### Task 12: Release importer v0.2.0 and update the Lunar app

Controller step (publishing to the user's own GitHub repos is pre-authorized; the Lunar app repo is never committed).

- [ ] **Step 1: Full verification on the branch**

Run in `packages/lunar-cjdropshipping`: `vendor/bin/phpunit` and `vendor/bin/phpstan analyse --memory-limit=2G`
Expected: all green.

- [ ] **Step 2: Merge, tag, push**

```bash
git checkout main
git merge --ff-only feat/catalog-listing
vendor/bin/phpunit
git tag v0.2.0
git push origin main v0.2.0
git branch -d feat/catalog-listing
```

- [ ] **Step 3: Update the Lunar app (no commit)**

In `C:\laraenv\www\lunar\composer.json` change `"thayron/cjdropshipping-php": "dev-main as 0.1.0"` to `"thayron/cjdropshipping-php": "dev-main as 0.2.0"`, then from `C:\laraenv\www\lunar`:

```bash
composer update thayron/cjdropshipping-php thayron/lunar-cjdropshipping
php artisan migrate --no-interaction
php artisan optimize:clear
php artisan queue:restart
```

Then restart the worker in the background: `php artisan queue:work --queue=cjdropshipping,default --timeout=3600 --tries=0 --sleep=3`.

- [ ] **Step 4: Smoke check**

`php artisan route:list --path=admin | grep -i -E "cj-catalog|confirm"` shows the catalog page and `/{record}/confirm`. Report the admin URLs to the user (use `https://lunar.test`).
