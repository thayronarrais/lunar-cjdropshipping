# lunar-cjdropshipping — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Package Laravel `thayron/lunar-cjdropshipping` que importa produtos da CJdropshipping para o Lunar 1.4 por regras com aprovação no admin Filament, e mantém estoque/preço sincronizados via webhooks e reconciliação agendada.

**Architecture:** Actions contêm a regra de negócio (descoberta, importação, imagens, sync, variantes novas) e usam o SDK `thayron/cjdropshipping-php` via `CjClient`. Jobs finos (fila `cjdropshipping`) chamam as Actions e tratam quota/retry. Tabelas próprias guardam regras, candidatos e vínculos CJ↔Lunar. Um plugin Filament expõe Regras, Candidatos e Produtos CJ no painel Lunar. Um `Throttle` espaça as chamadas à CJ (req/s configurável).

**Tech Stack:** PHP ^8.2, Laravel 11, `lunarphp/core` + `lunarphp/lunar` 1.4 (Filament 3.3), spatie/laravel-medialibrary 11, `thayron/cjdropshipping-php` ^0.1, PHPUnit 11, Orchestra Testbench 9, Guzzle MockHandler, Larastan, Pint.

**Spec:** `docs/specs/2026-09-13-lunar-cjdropshipping-design.md` (neste repositório)

## Global Constraints

- Diretório do package: `C:\laraenv\www\lunar\packages\lunar-cjdropshipping` (repo git próprio, branch `main` → trabalhar em branch `feat/importer`). Comandos rodam nesse diretório salvo indicação.
- Composer `thayron/lunar-cjdropshipping`; namespace `Thayron\LunarCjDropshipping\`; testes `Thayron\LunarCjDropshipping\Tests\`.
- Todo arquivo PHP começa com `<?php` + `declare(strict_types=1);` (exceto arquivos de migration/config/lang/routes, que seguem o estilo Laravel sem strict_types).
- Nenhuma moeda fixa: preços por `Currency` habilitada; taxa USD→moeda padrão via `Currency` `USD` ou `config('lunar-cjdropshipping.pricing.usd_to_default_rate')`.
- Valores monetários e decimais da CJ tratados como string + BCMath (escala 12); nunca `float` em cálculo de preço.
- Produto importado sempre `status = 'draft'`; conteúdo CJ (inglês) replicado para todas as `Language`.
- Sync nunca altera nome, descrição, imagens ou opções existentes.
- Nenhum teste acessa rede, exceto `tests/Integration/*` (grupo `live`, excluído por padrão).
- Métodos de teste snake_case com prefixo `test_`; data providers `public static`.
- Tabelas próprias com prefixo `cj_`; tabelas Lunar referenciadas via `(new Model)->getTable()`.
- Fila padrão `config('lunar-cjdropshipping.queue')` = `cjdropshipping`.
- Commits terminam com:
  ```
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw
  ```

## Ajustes em relação à spec

1. **Throttle em vez de `RateLimited`**: o middleware `RateLimited` limita início de jobs, não requisições; a descoberta faz várias chamadas por job. Um `Support\Throttle` (singleton, `requests_per_second`) é chamado antes de cada requisição à CJ. Com 1 worker isso respeita o QPS. Testes vinculam `new Throttle(0)` (sem espera).
2. **`sellPrice` da CJ pode ser faixa** (`"1.20-3.50"`, verificado na documentação): `Pricing\CostParser::lowest()` usa o menor valor.
3. **URLs das imagens** são passadas ao `ImportProductImagesJob` pela importação (evita outra chamada `find()`).
4. **`VariantWriter` + `OptionResolver`** (em `src/Catalog/`) são compartilhados por `ImportProduct` e `ImportNewVariants` (DRY).
5. **Exceções próprias**: `Exceptions\ImportException` (pré-condições/dados) e `Exceptions\PricingException extends ImportException`.
6. **Log**: `Support\CjLog::channel()` usa `config('lunar-cjdropshipping.log_channel')` ou o canal padrão.
7. **Testes Filament** usam um `tests/Support/TestPanelProvider` que registra o painel Lunar com o plugin (e desliga 2FA).

## File Map

```
composer.json, phpunit.xml, phpstan.neon, .gitignore, LICENSE
config/lunar-cjdropshipping.php                                   (T1)
database/migrations/2026_09_13_000000_create_cj_import_tables.php (T2)
lang/en/admin.php, lang/pt_BR/admin.php                           (T12)
routes/webhooks.php                                               (T10)
src/
  LunarCjDropshippingServiceProvider.php                          (T1, cresce T5, T8, T10, T11, T12)
  Enums/CandidateStatus.php, CjProductStatus.php, PriceRounding.php, UnavailableAction.php (T2)
  Models/ImportRule.php, Candidate.php, ProductLink.php, VariantLink.php                   (T2)
  Exceptions/ImportException.php, PricingException.php           (T3)
  Pricing/CostParser.php, PriceCalculator.php                     (T3)
  Mapping/VariantOptionParser.php, MeasurementConverter.php, StockResolver.php (T4)
  Support/Throttle.php, QuotaDelay.php, CjLog.php                 (T5)
  Actions/DiscoverCandidates.php                                  (T5)
  Jobs/DiscoverCandidatesJob.php                                  (T5)
  Console/DiscoverCommand.php                                     (T5)
  Media/ImageDownloader.php, HttpImageDownloader.php              (T6)
  Support/MediaCollection.php                                     (T6)
  Actions/ImportProductImages.php, Jobs/ImportProductImagesJob.php (T6)
  Catalog/ImportResult.php, OptionResolver.php, VariantWriter.php  (T7)
  Actions/ImportProduct.php, Jobs/ImportProductJob.php            (T7)
  Actions/SyncProduct.php, Jobs/SyncProductJob.php, Console/SyncCommand.php (T8)
  Actions/ImportNewVariants.php                                   (T9)
  Http/Controllers/WebhookController.php, Console/WebhooksSetupCommand.php (T10)
  Filament/CjDropshippingPlugin.php                               (T12)
  Filament/Support/CjCatalogOptions.php, PricePreview.php         (T12)
  Filament/Resources/ImportRuleResource.php (+ Pages)             (T12)
  Filament/Resources/CandidateResource.php (+ Pages)              (T13)
  Filament/Resources/ProductLinkResource.php (+ Pages)            (T13)
tests/
  TestCase.php, FilamentTestCase.php
  Support/CreatesLunarBaseline.php, FakeCj.php, ImportsFixtureProduct.php, TestPanelProvider.php
  Fixtures/*.json
  Unit/..., Feature/..., Filament/..., Integration/LiveImportTest.php
```

---

### Task 1: Scaffold, config, provider e harness de testes com Lunar

**Files:**
- Create: `composer.json`, `phpunit.xml`, `phpstan.neon`, `.gitignore`, `LICENSE`
- Create: `config/lunar-cjdropshipping.php`, `src/LunarCjDropshippingServiceProvider.php`, `database/migrations/.gitkeep`
- Create: `tests/TestCase.php`, `tests/Support/CreatesLunarBaseline.php`
- Test: `tests/Feature/PackageBootTest.php`

**Interfaces:**
- Produces: `Thayron\LunarCjDropshipping\Tests\TestCase` (Testbench + providers Lunar core/SDK/package, SQLite memória, `RefreshDatabase`, cache `array`, queue `sync`, `media-library.disk_name=public`, `cjdropshipping.api_key=test-api-key`).
- Produces: trait `Tests\Support\CreatesLunarBaseline` com `createLunarBaseline(bool $withUsd = true): void` e propriedades `Currency $eur`, `Currency $gbp`, `?Currency $usd`, `Channel $channel`, `TaxClass $taxClass`, `ProductType $productType`, `Language $english`, `Language $french`.
- Produces: config `lunar-cjdropshipping.*` (chaves abaixo).

- [ ] **Step 1: Criar branch e arquivos de projeto**

Run: `git checkout -b feat/importer`

`composer.json`:
```json
{
    "name": "thayron/lunar-cjdropshipping",
    "description": "Import CJdropshipping products into Lunar stores and keep stock and prices in sync.",
    "type": "library",
    "license": "MIT",
    "keywords": ["lunar", "laravel", "cjdropshipping", "dropshipping", "ecommerce", "filament"],
    "require": {
        "php": "^8.2",
        "lunarphp/core": "^1.4",
        "lunarphp/lunar": "^1.4",
        "thayron/cjdropshipping-php": "^0.1"
    },
    "require-dev": {
        "guzzlehttp/guzzle": "^7.8",
        "larastan/larastan": "^3.0",
        "laravel/pint": "^1.18",
        "orchestra/testbench": "^9.0",
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Thayron\\LunarCjDropshipping\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Thayron\\LunarCjDropshipping\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Thayron\\LunarCjDropshipping\\LunarCjDropshippingServiceProvider"
            ]
        }
    },
    "scripts": {
        "test": "phpunit",
        "analyse": "phpstan analyse --memory-limit=2G",
        "format": "pint"
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "php-http/discovery": true
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Package">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <groups>
        <exclude>
            <group>live</group>
        </exclude>
    </groups>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="testing"/>
    </php>
</phpunit>
```

`phpstan.neon`:
```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 6
    paths:
        - src
```

`.gitignore`:
```
/vendor/
composer.lock
.phpunit.cache/
.phpunit.result.cache
.superpowers/
```

`LICENSE`: copie exatamente o arquivo `C:\laraenv\www\lunar\packages\cjdropshipping-php\LICENSE` (MIT, "Copyright (c) 2026 Thayron Arrais").

- [ ] **Step 2: Instalar dependências**

Run: `composer install --no-interaction`
Expected: termina sem erro (baixa Lunar, Filament, SDK `thayron/cjdropshipping-php` do Packagist). Se faltar extensão PHP exigida pelo Lunar (`ext-intl`, `ext-bcmath`, `ext-exif`), reporte BLOCKED com a mensagem exata.

- [ ] **Step 3: Criar config e provider**

`config/lunar-cjdropshipping.php`:
```php
<?php

return [

    /*
    | Queue used by all importer jobs. Run a single worker on it:
    | php artisan queue:work --queue=cjdropshipping
    */
    'queue' => env('CJ_IMPORT_QUEUE', 'cjdropshipping'),

    /*
    | Maximum CJdropshipping API requests per second made by this package
    | (Free 1, Plus 2, Prime 4, Advanced 6). 0 disables throttling.
    */
    'requests_per_second' => (int) env('CJ_REQUESTS_PER_SECOND', 1),

    'schedule' => [
        'enabled' => (bool) env('CJ_SCHEDULE_ENABLED', true),
        'discover' => 'daily',
        'sync' => 'everySixHours',
    ],

    'sync' => [
        'stale_after_hours' => 6,
        'not_found_threshold' => 2,
        // 'out_of_stock' keeps the product published with zero stock; 'draft' also unpublishes it.
        'unavailable_action' => 'out_of_stock',
    ],

    'webhooks' => [
        'path' => 'cjdropshipping/webhook',
        'dedupe_ttl_hours' => 48,
    ],

    'media' => [
        // null uses config('lunar.media.collection').
        'collection' => null,
    ],

    'pricing' => [
        // Value of 1 USD in the store default currency; used only when no USD currency exists in Lunar.
        'usd_to_default_rate' => env('CJ_USD_TO_DEFAULT_RATE'),
    ],

    // null uses the application default log channel.
    'log_channel' => env('CJ_LOG_CHANNEL'),

];
```

`src/LunarCjDropshippingServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping;

use Illuminate\Support\ServiceProvider;

final class LunarCjDropshippingServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../config/lunar-cjdropshipping.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'lunar-cjdropshipping');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => $this->app->configPath('lunar-cjdropshipping.php')], 'lunar-cjdropshipping-config');
        }
    }
}
```

Create empty `database/migrations/.gitkeep`.

- [ ] **Step 4: Criar o harness de testes**

`tests/TestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Thayron\LunarCjDropshipping\LunarCjDropshippingServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const API_KEY = 'test-api-key';

    protected function getPackageProviders($app): array
    {
        return [
            \Spatie\LaravelBlink\BlinkServiceProvider::class,
            \Cartalyst\Converter\Laravel\ConverterServiceProvider::class,
            \Kalnoy\Nestedset\NestedSetServiceProvider::class,
            \Spatie\MediaLibrary\MediaLibraryServiceProvider::class,
            \Spatie\Activitylog\ActivitylogServiceProvider::class,
            \Lunar\LunarServiceProvider::class,
            \Thayron\CjDropshipping\Laravel\CjDropshippingServiceProvider::class,
            LunarCjDropshippingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.discard', ['driver' => 'null']);
        $app['config']->set('media-library.disk_name', 'public');
        $app['config']->set('media-library.queue_connection_name', 'discard');
        $app['config']->set('cjdropshipping.api_key', self::API_KEY);
        $app['config']->set('lunar-cjdropshipping.requests_per_second', 0);
        $app['config']->set('lunar-cjdropshipping.schedule.enabled', false);
    }
}
```

`tests/Support/CreatesLunarBaseline.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Support;

use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Attribute;
use Lunar\Models\AttributeGroup;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\TaxClass;

trait CreatesLunarBaseline
{
    protected Language $english;

    protected Language $french;

    protected Currency $eur;

    protected Currency $gbp;

    protected ?Currency $usd = null;

    protected Channel $channel;

    protected TaxClass $taxClass;

    protected ProductType $productType;

    /**
     * EUR default store with GBP and (optionally) USD, English + French.
     */
    protected function createLunarBaseline(bool $withUsd = true): void
    {
        $this->english = Language::factory()->create(['code' => 'en', 'name' => 'English', 'default' => true]);
        $this->french = Language::factory()->create(['code' => 'fr', 'name' => 'French', 'default' => false]);

        $this->eur = Currency::factory()->create([
            'code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'enabled' => true, 'default' => true,
        ]);
        $this->gbp = Currency::factory()->create([
            'code' => 'GBP', 'name' => 'Pound Sterling', 'exchange_rate' => 0.85, 'decimal_places' => 2, 'enabled' => true, 'default' => false,
        ]);

        if ($withUsd) {
            $this->usd = Currency::factory()->create([
                'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => 1.08, 'decimal_places' => 2, 'enabled' => true, 'default' => false,
            ]);
        }

        $this->channel = Channel::factory()->create(['name' => 'Webstore', 'handle' => 'webstore', 'default' => true]);
        CustomerGroup::factory()->create(['name' => 'Retail', 'handle' => 'retail', 'default' => true]);
        $this->taxClass = TaxClass::factory()->create(['name' => 'Default', 'default' => true]);

        $group = AttributeGroup::factory()->create([
            'attributable_type' => Product::morphName(),
            'name' => ['en' => 'Details'],
            'handle' => 'details',
            'position' => 1,
        ]);

        $attributes = collect(['name' => 'Name', 'description' => 'Description'])->map(fn (string $label, string $handle) => Attribute::factory()->create([
            'attribute_type' => Product::morphName(),
            'attribute_group_id' => $group->id,
            'position' => $handle === 'name' ? 1 : 2,
            'name' => ['en' => $label],
            'handle' => $handle,
            'section' => 'main',
            'type' => TranslatedText::class,
            'required' => $handle === 'name',
            'system' => true,
            'searchable' => true,
            'filterable' => false,
            'configuration' => [],
        ]));

        $this->productType = ProductType::factory()->create(['name' => 'CJ Products']);
        $this->productType->mappedAttributes()->attach($attributes->pluck('id')->all());
    }
}
```

- [ ] **Step 5: Escrever o teste que falha**

`tests/Feature/PackageBootTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature;

use Lunar\Models\Currency;
use Lunar\Models\Product;
use Thayron\CjDropshipping\CjClient;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class PackageBootTest extends TestCase
{
    use CreatesLunarBaseline;

    public function test_merges_package_config(): void
    {
        $this->assertSame('cjdropshipping', config('lunar-cjdropshipping.queue'));
        $this->assertSame('cjdropshipping/webhook', config('lunar-cjdropshipping.webhooks.path'));
        $this->assertSame(2, config('lunar-cjdropshipping.sync.not_found_threshold'));
    }

    public function test_resolves_the_cj_client_from_the_sdk_bridge(): void
    {
        $this->assertInstanceOf(CjClient::class, $this->app->make(CjClient::class));
    }

    public function test_baseline_creates_a_eur_store_with_gbp_and_usd(): void
    {
        $this->createLunarBaseline();

        $this->assertSame('EUR', Currency::getDefault()?->code);
        $this->assertSame(3, Currency::query()->where('enabled', true)->count());

        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        $this->assertTrue($product->exists);
    }
}
```

- [ ] **Step 6: Rodar**

Run: `vendor/bin/phpunit tests/Feature/PackageBootTest.php`
Expected: `OK (3 tests, ...)`. (Este task é de infraestrutura: a "falha" esperada antes dos Steps 3–4 é classe inexistente.) Se as migrations do Lunar falharem no SQLite, ou faltar tabela (`media`, `activity_log`), reporte BLOCKED com o erro exato — o Lunar core já inclui essas migrations (`2021_08_10_101547_create_media_table.php`, `2021_08_17_142630_create_activity_log_table.php`).

- [ ] **Step 7: Commit**

```bash
git add composer.json phpunit.xml phpstan.neon .gitignore LICENSE config src database tests
git commit -m "chore: scaffold importer package with Lunar test harness" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 2: Migrations, enums e models

**Files:**
- Create: `database/migrations/2026_09_13_000000_create_cj_import_tables.php` (remova `database/migrations/.gitkeep`)
- Create: `src/Enums/CandidateStatus.php`, `src/Enums/CjProductStatus.php`, `src/Enums/PriceRounding.php`, `src/Enums/UnavailableAction.php`
- Create: `src/Models/ImportRule.php`, `src/Models/Candidate.php`, `src/Models/ProductLink.php`, `src/Models/VariantLink.php`
- Test: `tests/Feature/Models/ModelsTest.php`

**Interfaces:**
- Consumes: `TestCase`, `CreatesLunarBaseline` (Task 1).
- Produces enums:
  - `CandidateStatus: string` — `Pending='pending'`, `Approved='approved'`, `Ignored='ignored'`, `Importing='importing'`, `Imported='imported'`, `Failed='failed'`.
  - `CjProductStatus: string` — `Active='active'`, `Unavailable='unavailable'`.
  - `PriceRounding: string` — `None='none'`, `Ends90='ends_90'`, `Ends99='ends_99'`, `Whole='whole'`.
  - `UnavailableAction: string` — `OutOfStock='out_of_stock'`, `Draft='draft'`.
- Produces models (all `$guarded = []`):
  - `ImportRule` (`cj_import_rules`): casts `is_active` bool, `category_ids` array, `min_stock`/`max_pages` integer, `min_cost`/`max_cost`/`markup_percent` `decimal:2`, `rounding` `PriceRounding`, `last_run_at` datetime, `last_run_stats` array; relations `productType()`, `brand()`, `collection()` (BelongsTo Lunar), `candidates()` HasMany, `productLinks()` HasMany.
  - `Candidate` (`cj_candidates`): casts `status` `CandidateStatus`, `cost_usd` `decimal:2`, `warehouse_stock` integer, `payload` array, `discovered_at` datetime; relations `importRule()`, `product()` (BelongsTo `Lunar\Models\Product` via `lunar_product_id`).
  - `ProductLink` (`cj_product_links`): casts `markup_percent` `decimal:2`, `rounding` `PriceRounding`, `cj_status` `CjProductStatus`, `not_found_count` integer, `new_cj_variant_ids` array, `last_synced_at` datetime; relations `product()`, `importRule()`, `variantLinks()` HasMany.
  - `VariantLink` (`cj_variant_links`): casts `cost_usd` `decimal:2`, `stock` integer, `last_synced_at` datetime; relations `productLink()`, `variant()` (BelongsTo `Lunar\Models\ProductVariant` via `lunar_variant_id`).

- [ ] **Step 1: Escrever o teste que falha**

`tests/Feature/Models/ModelsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Models;

use Illuminate\Database\QueryException;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ModelsTest extends TestCase
{
    use CreatesLunarBaseline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
    }

    public function test_import_rule_casts_and_relations(): void
    {
        $rule = ImportRule::create([
            'name' => 'Phone cases',
            'category_ids' => ['cat-1', 'cat-2'],
            'markup_percent' => '120.5',
            'rounding' => PriceRounding::Ends90,
            'product_type_id' => $this->productType->id,
            'max_pages' => 3,
        ])->fresh();

        $this->assertTrue($rule->is_active);
        $this->assertSame(['cat-1', 'cat-2'], $rule->category_ids);
        $this->assertSame('120.50', $rule->markup_percent);
        $this->assertSame(PriceRounding::Ends90, $rule->rounding);
        $this->assertSame(0, $rule->min_stock);
        $this->assertTrue($rule->productType->is($this->productType));
    }

    public function test_candidate_belongs_to_rule_and_is_unique_per_rule(): void
    {
        $rule = $this->rule();
        $candidate = Candidate::create([
            'import_rule_id' => $rule->id,
            'cj_product_id' => 'pid-1',
            'name' => 'Case',
            'cost_usd' => '3.5',
            'status' => CandidateStatus::Pending,
            'payload' => ['id' => 'pid-1'],
            'discovered_at' => now(),
        ])->fresh();

        $this->assertSame(CandidateStatus::Pending, $candidate->status);
        $this->assertSame('3.50', $candidate->cost_usd);
        $this->assertSame(['id' => 'pid-1'], $candidate->payload);
        $this->assertTrue($candidate->importRule->is($rule));

        $this->expectException(QueryException::class);

        Candidate::create([
            'import_rule_id' => $rule->id,
            'cj_product_id' => 'pid-1',
            'name' => 'Duplicate',
            'status' => CandidateStatus::Pending,
            'payload' => [],
            'discovered_at' => now(),
        ]);
    }

    public function test_product_and_variant_links(): void
    {
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'tax_class_id' => $this->taxClass->id]);

        $link = ProductLink::create([
            'cj_product_id' => 'pid-1',
            'lunar_product_id' => $product->id,
            'import_rule_id' => $this->rule()->id,
            'markup_percent' => '100',
            'rounding' => PriceRounding::None,
            'cj_status' => CjProductStatus::Active,
            'new_cj_variant_ids' => [],
        ])->fresh();

        $variantLink = VariantLink::create([
            'cj_variant_id' => 'vid-1',
            'cj_product_link_id' => $link->id,
            'lunar_variant_id' => $variant->id,
            'cost_usd' => '10',
            'stock' => 7,
        ]);

        $this->assertSame(CjProductStatus::Active, $link->cj_status);
        $this->assertSame([], $link->new_cj_variant_ids);
        $this->assertSame(0, $link->not_found_count);
        $this->assertTrue($link->product->is($product));
        $this->assertTrue($link->variantLinks->first()->is($variantLink));
        $this->assertTrue($variantLink->variant->is($variant));
        $this->assertSame('10.00', $variantLink->fresh()->cost_usd);
    }

    public function test_product_link_is_unique_per_cj_product(): void
    {
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        $attributes = [
            'cj_product_id' => 'pid-1',
            'lunar_product_id' => $product->id,
            'markup_percent' => '0',
            'rounding' => PriceRounding::None,
            'cj_status' => CjProductStatus::Active,
            'new_cj_variant_ids' => [],
        ];

        ProductLink::create($attributes);

        $this->expectException(QueryException::class);

        ProductLink::create($attributes);
    }

    private function rule(): ImportRule
    {
        return ImportRule::create([
            'name' => 'Rule',
            'keyword' => 'case',
            'markup_percent' => '100',
            'rounding' => PriceRounding::None,
            'product_type_id' => $this->productType->id,
        ]);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Feature/Models/ModelsTest.php`
Expected: FAIL — `Class "Thayron\LunarCjDropshipping\Models\ImportRule" not found`.

- [ ] **Step 3: Criar a migration**

`database/migrations/2026_09_13_000000_create_cj_import_tables.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lunar\Models\Brand;
use Lunar\Models\Collection;
use Lunar\Models\Product;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;

return new class extends Migration
{
    public function up(): void
    {
        $products = (new Product)->getTable();
        $variants = (new ProductVariant)->getTable();

        Schema::create('cj_import_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->json('category_ids')->nullable();
            $table->string('keyword')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->unsignedInteger('min_stock')->default(0);
            $table->decimal('min_cost', 12, 2)->nullable();
            $table->decimal('max_cost', 12, 2)->nullable();
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->string('rounding')->default('none');
            $table->foreignId('product_type_id')->constrained((new ProductType)->getTable());
            $table->foreignId('brand_id')->nullable()->constrained((new Brand)->getTable())->nullOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained((new Collection)->getTable())->nullOnDelete();
            $table->unsignedSmallInteger('max_pages')->default(5);
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_run_stats')->nullable();
            $table->timestamps();
        });

        Schema::create('cj_candidates', function (Blueprint $table) use ($products) {
            $table->id();
            $table->foreignId('import_rule_id')->constrained('cj_import_rules')->cascadeOnDelete();
            $table->string('cj_product_id');
            $table->string('cj_sku')->nullable();
            $table->string('name');
            $table->text('image_url')->nullable();
            $table->decimal('cost_usd', 12, 2)->nullable();
            $table->integer('warehouse_stock')->nullable();
            $table->string('cj_category_id')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error')->nullable();
            $table->foreignId('lunar_product_id')->nullable()->constrained($products)->nullOnDelete();
            $table->json('payload');
            $table->timestamp('discovered_at');
            $table->timestamps();

            $table->unique(['import_rule_id', 'cj_product_id']);
        });

        Schema::create('cj_product_links', function (Blueprint $table) use ($products) {
            $table->id();
            $table->string('cj_product_id')->unique();
            $table->foreignId('lunar_product_id')->constrained($products)->cascadeOnDelete();
            $table->foreignId('import_rule_id')->nullable()->constrained('cj_import_rules')->nullOnDelete();
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->string('rounding')->default('none');
            $table->string('country_code', 2)->nullable();
            $table->string('cj_status')->default('active')->index();
            $table->unsignedTinyInteger('not_found_count')->default(0);
            $table->json('new_cj_variant_ids');
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('cj_variant_links', function (Blueprint $table) use ($variants) {
            $table->id();
            $table->string('cj_variant_id')->unique();
            $table->foreignId('cj_product_link_id')->constrained('cj_product_links')->cascadeOnDelete();
            $table->foreignId('lunar_variant_id')->constrained($variants)->cascadeOnDelete();
            $table->string('cj_sku')->nullable();
            $table->decimal('cost_usd', 12, 2)->nullable();
            $table->integer('stock')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cj_variant_links');
        Schema::dropIfExists('cj_product_links');
        Schema::dropIfExists('cj_candidates');
        Schema::dropIfExists('cj_import_rules');
    }
};
```

- [ ] **Step 4: Criar os enums**

`src/Enums/CandidateStatus.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Enums;

enum CandidateStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Ignored = 'ignored';
    case Importing = 'importing';
    case Imported = 'imported';
    case Failed = 'failed';
}
```

`src/Enums/CjProductStatus.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Enums;

enum CjProductStatus: string
{
    case Active = 'active';
    case Unavailable = 'unavailable';
}
```

`src/Enums/PriceRounding.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Enums;

enum PriceRounding: string
{
    case None = 'none';
    case Ends90 = 'ends_90';
    case Ends99 = 'ends_99';
    case Whole = 'whole';
}
```

`src/Enums/UnavailableAction.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Enums;

enum UnavailableAction: string
{
    case OutOfStock = 'out_of_stock';
    case Draft = 'draft';
}
```

- [ ] **Step 5: Criar os models**

`src/Models/ImportRule.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lunar\Models\Brand;
use Lunar\Models\Collection;
use Lunar\Models\ProductType;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property list<string>|null $category_ids
 * @property string|null $keyword
 * @property string|null $country_code
 * @property int $min_stock
 * @property string|null $min_cost
 * @property string|null $max_cost
 * @property string $markup_percent
 * @property PriceRounding $rounding
 * @property int $product_type_id
 * @property int|null $brand_id
 * @property int|null $collection_id
 * @property int $max_pages
 * @property \Illuminate\Support\Carbon|null $last_run_at
 * @property array<string, mixed>|null $last_run_stats
 */
class ImportRule extends Model
{
    protected $table = 'cj_import_rules';

    protected $guarded = [];

    protected $attributes = [
        'is_active' => true,
        'min_stock' => 0,
        'max_pages' => 5,
        'rounding' => 'none',
        'markup_percent' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'category_ids' => 'array',
            'min_stock' => 'integer',
            'max_pages' => 'integer',
            'min_cost' => 'decimal:2',
            'max_cost' => 'decimal:2',
            'markup_percent' => 'decimal:2',
            'rounding' => PriceRounding::class,
            'last_run_at' => 'datetime',
            'last_run_stats' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ProductType, $this>
     */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Collection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /**
     * @return HasMany<Candidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }

    /**
     * @return HasMany<ProductLink, $this>
     */
    public function productLinks(): HasMany
    {
        return $this->hasMany(ProductLink::class);
    }
}
```

`src/Models/Candidate.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;

/**
 * @property int $id
 * @property int $import_rule_id
 * @property string $cj_product_id
 * @property string|null $cj_sku
 * @property string $name
 * @property string|null $image_url
 * @property string|null $cost_usd
 * @property int|null $warehouse_stock
 * @property string|null $cj_category_id
 * @property CandidateStatus $status
 * @property string|null $error
 * @property int|null $lunar_product_id
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon $discovered_at
 * @property-read ImportRule $importRule
 */
class Candidate extends Model
{
    protected $table = 'cj_candidates';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CandidateStatus::class,
            'cost_usd' => 'decimal:2',
            'warehouse_stock' => 'integer',
            'payload' => 'array',
            'discovered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ImportRule, $this>
     */
    public function importRule(): BelongsTo
    {
        return $this->belongsTo(ImportRule::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'lunar_product_id');
    }
}
```

`src/Models/ProductLink.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;

/**
 * @property int $id
 * @property string $cj_product_id
 * @property int $lunar_product_id
 * @property int|null $import_rule_id
 * @property string $markup_percent
 * @property PriceRounding $rounding
 * @property string|null $country_code
 * @property CjProductStatus $cj_status
 * @property int $not_found_count
 * @property list<string> $new_cj_variant_ids
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $sync_error
 * @property-read Product|null $product
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VariantLink> $variantLinks
 */
class ProductLink extends Model
{
    protected $table = 'cj_product_links';

    protected $guarded = [];

    protected $attributes = [
        'not_found_count' => 0,
        'cj_status' => 'active',
        'new_cj_variant_ids' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'markup_percent' => 'decimal:2',
            'rounding' => PriceRounding::class,
            'cj_status' => CjProductStatus::class,
            'not_found_count' => 'integer',
            'new_cj_variant_ids' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'lunar_product_id');
    }

    /**
     * @return BelongsTo<ImportRule, $this>
     */
    public function importRule(): BelongsTo
    {
        return $this->belongsTo(ImportRule::class);
    }

    /**
     * @return HasMany<VariantLink, $this>
     */
    public function variantLinks(): HasMany
    {
        return $this->hasMany(VariantLink::class, 'cj_product_link_id');
    }
}
```

`src/Models/VariantLink.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\ProductVariant;

/**
 * @property int $id
 * @property string $cj_variant_id
 * @property int $cj_product_link_id
 * @property int $lunar_variant_id
 * @property string|null $cj_sku
 * @property string|null $cost_usd
 * @property int $stock
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property-read ProductLink $productLink
 * @property-read ProductVariant|null $variant
 */
class VariantLink extends Model
{
    protected $table = 'cj_variant_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:2',
            'stock' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductLink, $this>
     */
    public function productLink(): BelongsTo
    {
        return $this->belongsTo(ProductLink::class, 'cj_product_link_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'lunar_variant_id');
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Feature/Models/ModelsTest.php`
Expected: `OK (4 tests, ...)`. Depois `vendor/bin/phpunit` (suíte inteira) → `OK`.

- [ ] **Step 7: Commit**

```bash
git add database src/Enums src/Models tests/Feature/Models
git commit -m "feat: add import rule, candidate and link tables and models" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 3: Preço (CostParser + PriceCalculator)

**Files:**
- Create: `src/Exceptions/ImportException.php`, `src/Exceptions/PricingException.php`
- Create: `src/Pricing/CostParser.php`, `src/Pricing/PriceCalculator.php`
- Test: `tests/Unit/Pricing/CostParserTest.php`, `tests/Feature/Pricing/PriceCalculatorTest.php`

**Interfaces:**
- Consumes: `PriceRounding` (Task 2), baseline (Task 1).
- Produces: `ImportException extends RuntimeException`; `PricingException extends ImportException`.
- Produces: `CostParser::lowest(?string $value): ?string` — menor decimal num texto CJ (`"11.85"`, `"1.20-3.50"`, `"8.13 -- 8.62"`), com 2 casas; `null` se não houver número.
- Produces: `PriceCalculator` (sem dependências no construtor):
  - `usdRate(): string` — 1 USD em moeda padrão (escala 12); lança `PricingException`.
  - `priceFor(string $costUsd, string $markupPercent, PriceRounding $rounding, Currency $currency, ?string $usdRate = null): int` — unidades mínimas.
  - `pricesFor(string $costUsd, string $markupPercent, PriceRounding $rounding): array<int, int>` — `currency_id => price` para cada `Currency` com `enabled = true`.

- [ ] **Step 1: Escrever os testes que falham**

`tests/Unit/Pricing/CostParserTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Unit\Pricing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thayron\LunarCjDropshipping\Pricing\CostParser;

final class CostParserTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function costs(): array
    {
        return [
            'single' => ['11.85', '11.85'],
            'integer' => ['3', '3.00'],
            'range' => ['1.20-3.50', '1.20'],
            'spaced range' => ['8.62 -- 8.13', '8.13'],
            'null' => [null, null],
            'no number' => ['n/a', null],
        ];
    }

    #[DataProvider('costs')]
    public function test_returns_the_lowest_cost(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, CostParser::lowest($input));
    }
}
```

`tests/Feature/Pricing/PriceCalculatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Pricing;

use Lunar\Models\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\PricingException;
use Thayron\LunarCjDropshipping\Pricing\PriceCalculator;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class PriceCalculatorTest extends TestCase
{
    use CreatesLunarBaseline;

    public function test_converts_through_the_usd_currency_rate(): void
    {
        $this->createLunarBaseline();

        $prices = (new PriceCalculator)->pricesFor('10.00', '100', PriceRounding::Ends90);

        $this->assertSame([
            $this->eur->id => 1890,
            $this->gbp->id => 1590,
            $this->usd->id => 2090,
        ], $prices);
    }

    /**
     * @return array<string, array{PriceRounding, int, int}>
     */
    public static function roundings(): array
    {
        // cost 10.00 USD, markup 100% => EUR 18.5185..., GBP 15.7407...
        return [
            'none' => [PriceRounding::None, 1852, 1574],
            'ends 90' => [PriceRounding::Ends90, 1890, 1590],
            'ends 99' => [PriceRounding::Ends99, 1899, 1599],
            'whole' => [PriceRounding::Whole, 1900, 1600],
        ];
    }

    #[DataProvider('roundings')]
    public function test_applies_rounding(PriceRounding $rounding, int $expectedEur, int $expectedGbp): void
    {
        $this->createLunarBaseline();
        $calculator = new PriceCalculator;

        $this->assertSame($expectedEur, $calculator->priceFor('10.00', '100', $rounding, $this->eur));
        $this->assertSame($expectedGbp, $calculator->priceFor('10.00', '100', $rounding, $this->gbp));
    }

    public function test_ends_90_moves_up_when_the_price_is_already_whole(): void
    {
        $this->createLunarBaseline();

        // 21.60 USD * (1/1.08) = 20.00 EUR exactly -> 20.90
        $this->assertSame(2090, (new PriceCalculator)->priceFor('21.60', '0', PriceRounding::Ends90, $this->eur));
    }

    public function test_uses_the_configured_rate_when_there_is_no_usd_currency(): void
    {
        $this->createLunarBaseline(withUsd: false);
        config(['lunar-cjdropshipping.pricing.usd_to_default_rate' => '0.5']);

        $this->assertSame([
            $this->eur->id => 1000,
            $this->gbp->id => 850,
        ], (new PriceCalculator)->pricesFor('10.00', '100', PriceRounding::None));
    }

    public function test_usd_default_store_uses_rate_one(): void
    {
        $this->createLunarBaseline(withUsd: false);
        $this->eur->update(['default' => false]);
        $usd = Currency::factory()->create(['code' => 'USD', 'exchange_rate' => 1, 'decimal_places' => 2, 'enabled' => true, 'default' => true]);

        $this->assertSame('1', (new PriceCalculator)->usdRate());
        $this->assertSame(2000, (new PriceCalculator)->priceFor('10.00', '100', PriceRounding::None, $usd));
    }

    public function test_zero_decimal_currency_rounds_ends_to_whole(): void
    {
        $this->createLunarBaseline();
        $yen = Currency::factory()->create(['code' => 'JPY', 'exchange_rate' => 160.4, 'decimal_places' => 0, 'enabled' => true, 'default' => false]);

        // 20 USD / 1.08 * 160.4 = 2970.37 -> ceil 2971
        $this->assertSame(2971, (new PriceCalculator)->priceFor('10.00', '100', PriceRounding::Ends90, $yen));
    }

    public function test_throws_when_no_usd_rate_is_available(): void
    {
        $this->createLunarBaseline(withUsd: false);
        config(['lunar-cjdropshipping.pricing.usd_to_default_rate' => null]);

        $this->expectException(PricingException::class);

        (new PriceCalculator)->usdRate();
    }

    public function test_ignores_disabled_currencies(): void
    {
        $this->createLunarBaseline();
        $this->gbp->update(['enabled' => false]);

        $this->assertArrayNotHasKey($this->gbp->id, (new PriceCalculator)->pricesFor('10.00', '0', PriceRounding::None));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Unit/Pricing tests/Feature/Pricing`
Expected: FAIL — `Class "Thayron\LunarCjDropshipping\Pricing\CostParser" not found`.

- [ ] **Step 3: Implementar exceções e CostParser**

`src/Exceptions/ImportException.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Exceptions;

use RuntimeException;

class ImportException extends RuntimeException
{
}
```

`src/Exceptions/PricingException.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Exceptions;

class PricingException extends ImportException
{
}
```

`src/Pricing/CostParser.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

final class CostParser
{
    /**
     * Lowest amount in a CJ price string such as "11.85", "1.20-3.50" or "8.13 -- 8.62".
     */
    public static function lowest(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        preg_match_all('/\d+(?:\.\d+)?/', $value, $matches);

        if ($matches[0] === []) {
            return null;
        }

        $lowest = array_reduce(
            $matches[0],
            fn (?string $carry, string $amount): string => $carry === null || bccomp($amount, $carry, 12) < 0 ? $amount : $carry,
        );

        return bcadd((string) $lowest, '0', 2);
    }
}
```

- [ ] **Step 4: Implementar o PriceCalculator**

`src/Pricing/PriceCalculator.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Pricing;

use Lunar\Models\Currency;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\PricingException;

/**
 * Sale price = CJ cost (USD) x (1 + markup) converted to each enabled Lunar currency.
 * Lunar exchange rates are relative to the default currency.
 */
final class PriceCalculator
{
    private const SCALE = 12;

    /**
     * @return array<int, int> currency id => price in minor units
     */
    public function pricesFor(string $costUsd, string $markupPercent, PriceRounding $rounding): array
    {
        $usdRate = $this->usdRate();
        $prices = [];

        foreach (Currency::query()->where('enabled', true)->orderBy('id')->get() as $currency) {
            $prices[$currency->id] = $this->priceFor($costUsd, $markupPercent, $rounding, $currency, $usdRate);
        }

        return $prices;
    }

    public function priceFor(string $costUsd, string $markupPercent, PriceRounding $rounding, Currency $currency, ?string $usdRate = null): int
    {
        $usdRate ??= $this->usdRate();
        $currencyRate = $currency->default ? '1' : self::decimal($currency->exchange_rate);
        $multiplier = bcadd('1', bcdiv(self::decimal($markupPercent), '100', self::SCALE), self::SCALE);

        $major = bcmul(bcmul(bcmul(self::decimal($costUsd), $multiplier, self::SCALE), $usdRate, self::SCALE), $currencyRate, self::SCALE);

        return $this->round($major, (int) $currency->decimal_places, $rounding);
    }

    /**
     * Value of 1 USD in the store default currency.
     */
    public function usdRate(): string
    {
        $usd = Currency::query()->where('code', 'USD')->first();

        if ($usd !== null) {
            if ($usd->default) {
                return '1';
            }

            if (bccomp(self::decimal($usd->exchange_rate), '0', self::SCALE) > 0) {
                return bcdiv('1', self::decimal($usd->exchange_rate), self::SCALE);
            }
        }

        $configured = config('lunar-cjdropshipping.pricing.usd_to_default_rate');

        if (is_numeric($configured) && bccomp(self::decimal($configured), '0', self::SCALE) > 0) {
            return self::decimal($configured);
        }

        throw new PricingException('Cannot convert CJdropshipping USD costs: create a USD currency in Lunar or set lunar-cjdropshipping.pricing.usd_to_default_rate.');
    }

    private function round(string $major, int $decimalPlaces, PriceRounding $rounding): int
    {
        if ($decimalPlaces === 0 && in_array($rounding, [PriceRounding::Ends90, PriceRounding::Ends99], true)) {
            $rounding = PriceRounding::Whole;
        }

        $factor = bcpow('10', (string) $decimalPlaces);

        $roundedMajor = match ($rounding) {
            PriceRounding::None => $major,
            PriceRounding::Whole => self::ceil($major),
            PriceRounding::Ends90 => self::endingIn($major, '0.10'),
            PriceRounding::Ends99 => self::endingIn($major, '0.01'),
        };

        return (int) bcadd(bcmul($roundedMajor, $factor, self::SCALE), '0.5', 0);
    }

    private static function endingIn(string $major, string $offset): string
    {
        $candidate = bcsub(self::ceil($major), $offset, self::SCALE);

        if (bccomp($candidate, $major, self::SCALE) < 0) {
            $candidate = bcadd($candidate, '1', self::SCALE);
        }

        return $candidate;
    }

    private static function ceil(string $value): string
    {
        $integer = bcadd($value, '0', 0);

        return bccomp($value, $integer, self::SCALE) > 0 ? bcadd($integer, '1', 0) : $integer;
    }

    private static function decimal(mixed $value): string
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        return number_format((float) $value, self::SCALE, '.', '');
    }
}
```

Nota: `bcadd(x, '0.5', 0)` arredonda meio para cima porque todos os valores são positivos.

- [ ] **Step 5: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Unit/Pricing tests/Feature/Pricing`
Expected: `OK (17 tests, ...)`. Os valores esperados foram calculados com BCMath escala 12 (10,00 USD ×2 → EUR 18,518518518500, GBP 15,740740740725, USD 19,999999999980). Se algum valor divergir por arredondamento, reporte DONE_WITH_CONCERNS com a saída exata — não altere os valores esperados sem explicar.

- [ ] **Step 6: Commit**

```bash
git add src/Exceptions src/Pricing tests/Unit/Pricing tests/Feature/Pricing
git commit -m "feat: add currency-agnostic price calculator" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 4: Mapeamento (opções, medidas, estoque)

**Files:**
- Create: `src/Mapping/VariantOptionParser.php`, `src/Mapping/MeasurementConverter.php`, `src/Mapping/StockResolver.php`
- Test: `tests/Unit/Mapping/VariantOptionParserTest.php`, `tests/Unit/Mapping/MeasurementConverterTest.php`, `tests/Unit/Mapping/StockResolverTest.php`

**Interfaces:**
- Consumes: SDK `Thayron\CjDropshipping\Data\Variant::fromArray(array)` (campos `id`, `key`, `sku`), `Thayron\CjDropshipping\Data\ProductInventory::fromArray(array)` e `forVariant(string $vid): list<Inventory>` (`Inventory::$countryCode`, `Inventory::$total`).
- Produces: `VariantOptionParser::parse(mixed $productKeyEn, array $variants): array{options: list<string>, values: array<string, list<string>>}` — `values` indexado pelo id da variante CJ, na ordem de `options`.
- Produces: `MeasurementConverter::gramsToKilograms(?string $grams): ?string` e `millimetersToCentimeters(?string $millimeters): ?string` (4 casas, `null` preservado).
- Produces: `StockResolver::forVariant(ProductInventory $inventory, string $variantId, ?string $countryCode): int`.

- [ ] **Step 1: Escrever os testes que falham**

`tests/Unit/Mapping/VariantOptionParserTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Thayron\CjDropshipping\Data\Variant;
use Thayron\LunarCjDropshipping\Mapping\VariantOptionParser;

final class VariantOptionParserTest extends TestCase
{
    public function test_single_variant_has_no_options(): void
    {
        $result = (new VariantOptionParser)->parse('Color', [$this->variant('v1', 'Black')]);

        $this->assertSame(['options' => [], 'values' => []], $result);
    }

    public function test_splits_two_options(): void
    {
        $result = (new VariantOptionParser)->parse('Color-Size', [
            $this->variant('v1', 'Black-XL'),
            $this->variant('v2', 'White-M'),
        ]);

        $this->assertSame(['Color', 'Size'], $result['options']);
        $this->assertSame(['v1' => ['Black', 'XL'], 'v2' => ['White', 'M']], $result['values']);
    }

    public function test_keeps_hyphens_in_the_last_value(): void
    {
        $result = (new VariantOptionParser)->parse('Color-Size', [
            $this->variant('v1', 'Black-2-3 Years'),
            $this->variant('v2', 'White-4-5 Years'),
        ]);

        $this->assertSame(['v1' => ['Black', '2-3 Years'], 'v2' => ['White', '4-5 Years']], $result['values']);
    }

    public function test_accepts_json_encoded_option_names(): void
    {
        $result = (new VariantOptionParser)->parse('["Color","Size"]', [
            $this->variant('v1', 'Black-XL'),
            $this->variant('v2', 'White-M'),
        ]);

        $this->assertSame(['Color', 'Size'], $result['options']);
    }

    public function test_falls_back_to_a_single_variant_option_when_keys_do_not_match(): void
    {
        $result = (new VariantOptionParser)->parse('Color-Size-Style', [
            $this->variant('v1', 'Black-XL'),
            $this->variant('v2', 'White'),
        ]);

        $this->assertSame(['Variant'], $result['options']);
        $this->assertSame(['v1' => ['Black-XL'], 'v2' => ['White']], $result['values']);
    }

    public function test_falls_back_when_option_names_are_missing(): void
    {
        $result = (new VariantOptionParser)->parse(null, [
            $this->variant('v1', 'Black'),
            $this->variant('v2', null, 'SKU-2'),
        ]);

        $this->assertSame(['Variant'], $result['options']);
        $this->assertSame(['v1' => ['Black'], 'v2' => ['SKU-2']], $result['values']);
    }

    private function variant(string $id, ?string $key, ?string $sku = null): Variant
    {
        return Variant::fromArray(['vid' => $id, 'variantKey' => $key, 'variantSku' => $sku ?? 'SKU-'.$id]);
    }
}
```

`tests/Unit/Mapping/MeasurementConverterTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Thayron\LunarCjDropshipping\Mapping\MeasurementConverter;

final class MeasurementConverterTest extends TestCase
{
    public function test_converts_grams_and_millimeters(): void
    {
        $converter = new MeasurementConverter;

        $this->assertSame('1.5800', $converter->gramsToKilograms('1580'));
        $this->assertSame('30.0000', $converter->millimetersToCentimeters('300'));
        $this->assertNull($converter->gramsToKilograms(null));
        $this->assertNull($converter->millimetersToCentimeters('not-a-number'));
    }
}
```

`tests/Unit/Mapping/StockResolverTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Thayron\CjDropshipping\Data\ProductInventory;
use Thayron\LunarCjDropshipping\Mapping\StockResolver;

final class StockResolverTest extends TestCase
{
    public function test_sums_stock_and_filters_by_country(): void
    {
        $inventory = ProductInventory::fromArray([
            'inventories' => [],
            'variantInventories' => [
                ['vid' => 'v1', 'inventory' => [
                    ['countryCode' => 'CN', 'totalInventory' => 100],
                    ['countryCode' => 'US', 'totalInventory' => 7],
                    ['countryCode' => 'us', 'totalInventory' => 3],
                ]],
                ['vid' => '1796078021431009280', 'inventory' => [['countryCode' => 'CN', 'totalInventory' => 5]]],
            ],
        ]);
        $resolver = new StockResolver;

        $this->assertSame(110, $resolver->forVariant($inventory, 'v1', null));
        $this->assertSame(10, $resolver->forVariant($inventory, 'v1', 'US'));
        $this->assertSame(5, $resolver->forVariant($inventory, '1796078021431009280', null));
        $this->assertSame(0, $resolver->forVariant($inventory, 'missing', null));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Unit/Mapping`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implementar**

`src/Mapping/VariantOptionParser.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Mapping;

use Thayron\CjDropshipping\Data\Variant;

/**
 * Maps CJ "productKeyEn" (e.g. "Color-Size") and variant keys (e.g. "Black-XL") to Lunar options.
 */
final class VariantOptionParser
{
    public const FALLBACK_OPTION = 'Variant';

    /**
     * @param  list<Variant>  $variants
     * @return array{options: list<string>, values: array<string, list<string>>}
     */
    public function parse(mixed $productKeyEn, array $variants): array
    {
        if (count($variants) <= 1) {
            return ['options' => [], 'values' => []];
        }

        $names = $this->optionNames($productKeyEn);

        if ($names !== []) {
            $values = [];

            foreach ($variants as $variant) {
                $split = $this->split((string) $variant->key, count($names));

                if ($split === null) {
                    return $this->fallback($variants);
                }

                $values[$variant->id] = $split;
            }

            return ['options' => $names, 'values' => $values];
        }

        return $this->fallback($variants);
    }

    /**
     * @return list<string>
     */
    private function optionNames(mixed $productKeyEn): array
    {
        if (! is_string($productKeyEn) || trim($productKeyEn) === '') {
            return [];
        }

        $decoded = json_decode($productKeyEn, true);
        $parts = is_array($decoded) ? $decoded : explode('-', $productKeyEn);

        return array_values(array_filter(
            array_map(fn (mixed $part): string => is_scalar($part) ? trim((string) $part) : '', $parts),
            fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * @return list<string>|null
     */
    private function split(string $key, int $count): ?array
    {
        $parts = explode('-', $key);

        if (trim($key) === '' || count($parts) < $count) {
            return null;
        }

        $values = array_map('trim', [...array_slice($parts, 0, $count - 1), implode('-', array_slice($parts, $count - 1))]);

        return in_array('', $values, true) ? null : $values;
    }

    /**
     * @param  list<Variant>  $variants
     * @return array{options: list<string>, values: array<string, list<string>>}
     */
    private function fallback(array $variants): array
    {
        $values = [];

        foreach ($variants as $variant) {
            $label = trim((string) $variant->key);
            $values[$variant->id] = [$label !== '' ? $label : ($variant->sku ?? $variant->id)];
        }

        return ['options' => [self::FALLBACK_OPTION], 'values' => $values];
    }
}
```

`src/Mapping/MeasurementConverter.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Mapping;

/**
 * CJ sends weights in grams and dimensions in millimeters; the store uses kg and cm.
 */
final class MeasurementConverter
{
    public function gramsToKilograms(?string $grams): ?string
    {
        return self::divide($grams, '1000');
    }

    public function millimetersToCentimeters(?string $millimeters): ?string
    {
        return self::divide($millimeters, '10');
    }

    private static function divide(?string $value, string $divisor): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return bcdiv($value, $divisor, 4);
    }
}
```

`src/Mapping/StockResolver.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Mapping;

use Thayron\CjDropshipping\Data\ProductInventory;

final class StockResolver
{
    public function forVariant(ProductInventory $inventory, string $variantId, ?string $countryCode): int
    {
        $total = 0;

        foreach ($inventory->forVariant($variantId) as $record) {
            if ($countryCode !== null && strtoupper((string) $record->countryCode) !== strtoupper($countryCode)) {
                continue;
            }

            $total += max(0, $record->total);
        }

        return $total;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Unit/Mapping`
Expected: `OK (8 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/Mapping tests/Unit/Mapping
git commit -m "feat: add variant option, measurement and stock mapping" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 5: Suporte (Throttle, QuotaDelay, CjLog), CJ fake e descoberta de candidatos

**Files:**
- Create: `src/Support/Throttle.php`, `src/Support/QuotaDelay.php`, `src/Support/CjLog.php`
- Create: `src/Actions/DiscoverCandidates.php`, `src/Jobs/DiscoverCandidatesJob.php`, `src/Console/DiscoverCommand.php`
- Modify: `src/LunarCjDropshippingServiceProvider.php` (binding do Throttle + comando)
- Create: `tests/Support/FakeCj.php`, `tests/Fixtures/list-v2-page.json`, `tests/Fixtures/product-detail.json`, `tests/Fixtures/stock-by-pid.json`
- Test: `tests/Feature/Discovery/DiscoverCandidatesTest.php`

**Interfaces:**
- Consumes: models/enums (Task 2), `CostParser` (Task 3); SDK: `CjClient::products()->search(ProductSearch): Paginated<ProductSummary>` (`items`, `hasMorePages()`), `ProductSearch::make()->perPage(int)->keyword(string)->country(string)->category(string)->page(int)`, `ProductSummary` (`id`, `name`, `sku`, `image`, `sellPrice`, `categoryId`, `warehouseInventory`, `raw()`), `Thayron\CjDropshipping\Exceptions\QuotaExceededException`; SDK `Auth\AccessToken(string $accessToken, DateTimeImmutable $accessTokenExpiresAt, string $refreshToken, DateTimeImmutable $refreshTokenExpiresAt, ?string $openId)` + `toArray()`, `Auth\TokenManager::storeKeyFor(string $apiKey)`.
- Produces: `Support\Throttle(int $requestsPerSecond)` com `wait(): void` (singleton no container; `0` = sem espera).
- Produces: `Support\QuotaDelay::seconds(): int` (até 00:05 UTC do dia seguinte, mínimo 60).
- Produces: `Support\CjLog::channel(): Psr\Log\LoggerInterface`.
- Produces: `Actions\DiscoverCandidates::__construct(CjClient $cj, Throttle $throttle)` e `handle(ImportRule $rule): array{found:int, created:int, updated:int, skipped_ignored:int, already_imported:int}`.
- Produces: `Jobs\DiscoverCandidatesJob(ImportRule $rule)` (`ShouldQueue`, `ShouldBeUnique` por regra, fila da config, `tries = 3`, `backoff = [60, 300, 900]`).
- Produces: comando `cj:discover {--rule=*}`.
- Produces (testes): `Tests\Support\FakeCj::install(Application $app): FakeCj` (registra Guzzle `MockHandler` como `Psr\Http\Client\ClientInterface`, guarda token válido com openId `123456789` no cache), com `fixture(string $name)`, `success(mixed $data)`, `error(int $code, string $message = 'Error')`, `requests(): list<RequestInterface>`, `paths(): list<string>` (sem o prefixo `/api2.0/v1/`), `queryAt(int $index): array<string,string>`, `jsonAt(int $index): array`.

- [ ] **Step 1: Criar fixtures e o CJ fake**

`tests/Fixtures/list-v2-page.json`:
```json
{
    "code": 200,
    "result": true,
    "message": "Success",
    "data": {
        "pageSize": 100,
        "pageNumber": 1,
        "totalRecords": 2,
        "totalPages": 1,
        "content": [
            {
                "productList": [
                    {
                        "id": "p-100",
                        "nameEn": "Magnetic Phone Case",
                        "sku": "CJ-CASE",
                        "bigImage": "https://cf.cjdropshipping.com/case-1.jpg",
                        "sellPrice": "11.85",
                        "categoryId": "cat-1",
                        "listedNum": 40,
                        "warehouseInventoryNum": 500,
                        "verifiedWarehouse": 1
                    },
                    {
                        "id": "p-200",
                        "nameEn": "Cable Organizer",
                        "sku": "CJ-CABLE",
                        "bigImage": "https://cf.cjdropshipping.com/cable.jpg",
                        "sellPrice": "1.20-3.50",
                        "categoryId": "cat-1",
                        "listedNum": 3,
                        "warehouseInventoryNum": 3,
                        "verifiedWarehouse": 2
                    }
                ],
                "relatedCategoryList": [],
                "keyWord": "",
                "keyWordOld": ""
            }
        ]
    },
    "requestId": "list-v2-fixture"
}
```

`tests/Fixtures/product-detail.json`:
```json
{
    "code": 200,
    "result": true,
    "message": "Success",
    "data": {
        "pid": "p-100",
        "productNameEn": "Magnetic Phone Case",
        "productSku": "CJ-CASE",
        "productKeyEn": "Color-Size",
        "bigImage": "https://cf.cjdropshipping.com/case-1.jpg",
        "productImageSet": [
            "https://cf.cjdropshipping.com/case-1.jpg",
            "https://cf.cjdropshipping.com/case-2.jpg"
        ],
        "productWeight": "1580.0",
        "categoryId": "cat-1",
        "sellPrice": "8.13-10.00",
        "description": "<p>Strong magnets</p>",
        "status": "3",
        "variants": [
            {
                "vid": "v-1",
                "pid": "p-100",
                "variantNameEn": "Magnetic Phone Case Black XL",
                "variantSku": "CJ-CASE-BLK-XL",
                "variantKey": "Black-XL",
                "variantLength": 300,
                "variantWidth": 200,
                "variantHeight": 100,
                "variantWeight": 1580,
                "variantSellPrice": "10.00"
            },
            {
                "vid": "v-2",
                "pid": "p-100",
                "variantNameEn": "Magnetic Phone Case Navy Blue M",
                "variantSku": "CJ-CASE-NVY-M",
                "variantKey": "Navy Blue-M",
                "variantLength": 150,
                "variantWidth": 80,
                "variantHeight": 10,
                "variantWeight": 120,
                "variantSellPrice": "8.13"
            }
        ]
    },
    "requestId": "product-detail-fixture"
}
```

`tests/Fixtures/stock-by-pid.json`:
```json
{
    "success": true,
    "code": 200,
    "message": "",
    "data": {
        "inventories": [
            {"areaEn": "US Warehouse", "areaId": 2, "countryCode": "US", "totalInventoryNum": 7}
        ],
        "variantInventories": [
            {"vid": "v-1", "inventory": [
                {"countryCode": "CN", "totalInventory": 100, "cjInventory": 0, "factoryInventory": 100, "verifiedWarehouse": 2},
                {"countryCode": "US", "totalInventory": 7, "cjInventory": 7, "factoryInventory": 0, "verifiedWarehouse": 1}
            ]},
            {"vid": "v-2", "inventory": [
                {"countryCode": "CN", "totalInventory": 20, "cjInventory": 0, "factoryInventory": 20, "verifiedWarehouse": 2}
            ]}
        ]
    },
    "requestId": "stock-by-pid-fixture"
}
```

`tests/Support/FakeCj.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Support;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Thayron\CjDropshipping\Auth\AccessToken;
use Thayron\CjDropshipping\Auth\TokenManager;
use Thayron\CjDropshipping\CjClient;

/**
 * Queues CJdropshipping API responses for the SDK client resolved from the container.
 */
final class FakeCj
{
    public const OPEN_ID = '123456789';

    private MockHandler $handler;

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    public static function install(Application $app): self
    {
        $fake = new self;
        $fake->handler = new MockHandler;

        $stack = HandlerStack::create($fake->handler);
        $stack->push(Middleware::history($fake->history));

        $app->instance(ClientInterface::class, new Client(['handler' => $stack, 'http_errors' => false]));
        $app->forgetInstance(CjClient::class);

        $token = new AccessToken(
            'fake-access-token',
            new DateTimeImmutable('+10 days'),
            'fake-refresh-token',
            new DateTimeImmutable('+170 days'),
            self::OPEN_ID,
        );
        Cache::put(TokenManager::storeKeyFor((string) config('cjdropshipping.api_key')), $token->toArray(), 3600);

        return $fake;
    }

    public function fixture(string $name): self
    {
        $this->handler->append(new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__."/../Fixtures/{$name}.json")));

        return $this;
    }

    /**
     * @param  array<string, mixed>  $overrides  merged into the fixture "data"
     */
    public function fixtureWith(string $name, array $overrides): self
    {
        $envelope = json_decode((string) file_get_contents(__DIR__."/../Fixtures/{$name}.json"), true);
        $envelope['data'] = array_replace_recursive($envelope['data'], $overrides);

        return $this->json($envelope);
    }

    public function success(mixed $data): self
    {
        return $this->json(['code' => 200, 'result' => true, 'message' => 'Success', 'data' => $data, 'requestId' => 'fake']);
    }

    public function error(int $code, string $message = 'Error'): self
    {
        return $this->json(['code' => $code, 'result' => false, 'message' => $message, 'data' => null, 'requestId' => 'fake']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function json(array $body): self
    {
        $this->handler->append(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body)));

        return $this;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return array_values(array_map(fn (array $entry): RequestInterface => $entry['request'], $this->history));
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(
            fn (RequestInterface $request): string => (string) preg_replace('#^/api2\.0/v1/#', '', $request->getUri()->getPath()),
            $this->requests(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function queryAt(int $index): array
    {
        parse_str($this->requests()[$index]->getUri()->getQuery(), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function jsonAt(int $index): array
    {
        $decoded = json_decode((string) $this->requests()[$index]->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function remaining(): int
    {
        return $this->handler->count();
    }
}
```

- [ ] **Step 2: Escrever o teste que falha**

`tests/Feature/Discovery/DiscoverCandidatesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Discovery;

use Illuminate\Support\Facades\Queue;
use Lunar\Models\Product;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\LunarCjDropshipping\Actions\DiscoverCandidates;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Jobs\DiscoverCandidatesJob;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class DiscoverCandidatesTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
    }

    public function test_creates_pending_candidates_matching_the_rule_filters(): void
    {
        $rule = $this->rule(['category_ids' => ['cat-1'], 'keyword' => 'case', 'country_code' => 'us', 'min_stock' => 10]);
        $this->cj->fixture('list-v2-page');

        $stats = app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame(['found' => 1, 'created' => 1, 'updated' => 0, 'skipped_ignored' => 0, 'already_imported' => 0], $stats);
        $this->assertSame(['product/listV2'], $this->cj->paths());
        $this->assertSame(['keyWord' => 'case', 'categoryId' => 'cat-1', 'countryCode' => 'US', 'page' => '1', 'size' => '100'], $this->cj->queryAt(0));

        $candidate = Candidate::query()->sole();
        $this->assertSame('p-100', $candidate->cj_product_id);
        $this->assertSame('Magnetic Phone Case', $candidate->name);
        $this->assertSame('11.85', $candidate->cost_usd);
        $this->assertSame(500, $candidate->warehouse_stock);
        $this->assertSame(CandidateStatus::Pending, $candidate->status);
        $this->assertSame('CJ-CASE', $candidate->payload['sku']);
        $this->assertSame($stats, $rule->fresh()->last_run_stats);
        $this->assertNotNull($rule->fresh()->last_run_at);
    }

    public function test_filters_by_cost_range_using_the_lowest_price(): void
    {
        $rule = $this->rule(['keyword' => 'x', 'min_cost' => '1.00', 'max_cost' => '2.00']);
        $this->cj->fixture('list-v2-page');

        app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame(['p-200'], Candidate::query()->pluck('cj_product_id')->all());
        $this->assertSame('1.20', Candidate::query()->sole()->cost_usd);
    }

    public function test_runs_one_search_per_category(): void
    {
        $rule = $this->rule(['category_ids' => ['cat-1', 'cat-2']]);
        $this->cj->fixture('list-v2-page')->fixture('list-v2-page');

        $stats = app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame('cat-2', $this->cj->queryAt(1)['categoryId']);
        $this->assertSame(2, $stats['created']);
        $this->assertSame(2, $stats['updated']);
    }

    public function test_keeps_ignored_candidates_and_marks_linked_products_as_imported(): void
    {
        $rule = $this->rule(['keyword' => 'x']);
        Candidate::create([
            'import_rule_id' => $rule->id, 'cj_product_id' => 'p-100', 'name' => 'Old name',
            'status' => CandidateStatus::Ignored, 'payload' => [], 'discovered_at' => now()->subDay(),
        ]);
        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        ProductLink::create(['cj_product_id' => 'p-200', 'lunar_product_id' => $product->id, 'markup_percent' => '0', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []]);
        $this->cj->fixture('list-v2-page');

        $stats = app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame(1, $stats['skipped_ignored']);
        $this->assertSame(1, $stats['already_imported']);
        $ignored = Candidate::query()->where('cj_product_id', 'p-100')->sole();
        $this->assertSame(CandidateStatus::Ignored, $ignored->status);
        $this->assertSame('Old name', $ignored->name);
        $imported = Candidate::query()->where('cj_product_id', 'p-200')->sole();
        $this->assertSame(CandidateStatus::Imported, $imported->status);
        $this->assertSame($product->id, $imported->lunar_product_id);
    }

    public function test_updates_pending_candidates_on_rerun(): void
    {
        $rule = $this->rule(['keyword' => 'x']);
        $this->cj->fixture('list-v2-page')->fixtureWith('list-v2-page', [
            'content' => [['productList' => [['id' => 'p-100', 'nameEn' => 'Magnetic Phone Case', 'sellPrice' => '9.99', 'warehouseInventoryNum' => 42]]]],
        ]);

        app(DiscoverCandidates::class)->handle($rule);
        $stats = app(DiscoverCandidates::class)->handle($rule);

        $this->assertSame(2, $stats['updated']);
        $candidate = Candidate::query()->where('cj_product_id', 'p-100')->sole();
        $this->assertSame('9.99', $candidate->cost_usd);
        $this->assertSame(42, $candidate->warehouse_stock);
    }

    public function test_respects_max_pages(): void
    {
        $rule = $this->rule(['keyword' => 'x', 'max_pages' => 1]);
        $this->cj->fixtureWith('list-v2-page', ['totalPages' => 9]);

        app(DiscoverCandidates::class)->handle($rule);

        $this->assertCount(1, $this->cj->requests());
    }

    public function test_records_the_error_and_rethrows(): void
    {
        config(['cjdropshipping.max_retries' => 0]);
        $this->app->forgetInstance(\Thayron\CjDropshipping\CjClient::class);
        $rule = $this->rule(['keyword' => 'x']);
        $this->cj->error(1600000, 'System busy')->error(1600000)->error(1600000);

        try {
            app(DiscoverCandidates::class)->handle($rule);
            $this->fail('Expected ServerException.');
        } catch (ServerException) {
            $this->assertStringContainsString('System busy', (string) $rule->fresh()->last_run_stats['error']);
        }
    }

    public function test_job_releases_until_tomorrow_when_quota_is_exhausted(): void
    {
        $rule = $this->rule(['keyword' => 'x']);
        $this->cj->error(1600201, 'Daily quota exhausted');

        $job = (new DiscoverCandidatesJob($rule))->withFakeQueueInteractions();
        $job->handle(app(DiscoverCandidates::class));

        $job->assertReleased();
    }

    public function test_command_dispatches_jobs_for_active_rules(): void
    {
        Queue::fake();
        $active = $this->rule(['keyword' => 'a']);
        $this->rule(['keyword' => 'b', 'is_active' => false]);

        $this->artisan('cj:discover')->expectsOutput('Dispatched 1 discovery job(s).')->assertSuccessful();

        Queue::assertPushed(DiscoverCandidatesJob::class, fn (DiscoverCandidatesJob $job) => $job->rule->is($active));
        Queue::assertPushedOn('cjdropshipping', DiscoverCandidatesJob::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rule(array $attributes): ImportRule
    {
        return ImportRule::create([
            'name' => 'Rule',
            'markup_percent' => '100',
            'rounding' => PriceRounding::Ends90,
            'product_type_id' => $this->productType->id,
            ...$attributes,
        ]);
    }
}
```

Nota: em `test_runs_one_search_per_category` a segunda busca retorna os mesmos pids — ela cria 2 e atualiza 2 (os já criados na primeira categoria).

- [ ] **Step 3: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Feature/Discovery`
Expected: FAIL — `Class "Thayron\LunarCjDropshipping\Actions\DiscoverCandidates" not found`.

- [ ] **Step 4: Implementar suporte**

`src/Support/Throttle.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Support;

/**
 * Spaces CJdropshipping API calls made by this process (0 disables it).
 */
final class Throttle
{
    private float $lastRequestAt = 0.0;

    public function __construct(private readonly int $requestsPerSecond)
    {
    }

    public function wait(): void
    {
        if ($this->requestsPerSecond <= 0) {
            return;
        }

        $interval = 1 / $this->requestsPerSecond;
        $elapsed = microtime(true) - $this->lastRequestAt;

        if ($this->lastRequestAt > 0 && $elapsed < $interval) {
            usleep((int) (($interval - $elapsed) * 1_000_000));
        }

        $this->lastRequestAt = microtime(true);
    }
}
```

`src/Support/QuotaDelay.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Support;

use Carbon\CarbonImmutable;

final class QuotaDelay
{
    /**
     * Seconds until 00:05 UTC tomorrow, when the CJ daily quota has reset.
     */
    public static function seconds(): int
    {
        $now = CarbonImmutable::now('UTC');

        return max(60, (int) $now->diffInSeconds($now->addDay()->startOfDay()->addMinutes(5), true));
    }
}
```

`src/Support/CjLog.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

final class CjLog
{
    public static function channel(): LoggerInterface
    {
        $channel = config('lunar-cjdropshipping.log_channel');

        return Log::channel(is_string($channel) && $channel !== '' ? $channel : null);
    }
}
```

- [ ] **Step 5: Implementar a Action, o Job e o comando**

`src/Actions/DiscoverCandidates.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Criteria\ProductSearch;
use Thayron\CjDropshipping\Data\ProductSummary;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Pricing\CostParser;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Throwable;

final class DiscoverCandidates
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
    ) {
    }

    /**
     * @return array{found: int, created: int, updated: int, skipped_ignored: int, already_imported: int}
     */
    public function handle(ImportRule $rule): array
    {
        $stats = ['found' => 0, 'created' => 0, 'updated' => 0, 'skipped_ignored' => 0, 'already_imported' => 0];

        try {
            foreach ($this->searches($rule) as $search) {
                for ($page = 1; $page <= $rule->max_pages; $page++) {
                    $this->throttle->wait();
                    $result = $this->cj->products()->search($search->page($page));

                    foreach ($result->items as $summary) {
                        if (! $this->matches($rule, $summary)) {
                            continue;
                        }

                        $stats['found']++;
                        $stats[$this->upsert($rule, $summary)]++;
                    }

                    if (! $result->hasMorePages()) {
                        break;
                    }
                }
            }
        } catch (Throwable $exception) {
            $rule->forceFill(['last_run_at' => now(), 'last_run_stats' => [...$stats, 'error' => $exception->getMessage()]])->save();

            throw $exception;
        }

        $rule->forceFill(['last_run_at' => now(), 'last_run_stats' => $stats])->save();

        return $stats;
    }

    /**
     * @return list<ProductSearch>
     */
    private function searches(ImportRule $rule): array
    {
        $base = ProductSearch::make()->perPage(100);

        if (filled($rule->keyword)) {
            $base = $base->keyword((string) $rule->keyword);
        }

        if (filled($rule->country_code)) {
            $base = $base->country((string) $rule->country_code);
        }

        $categories = array_values(array_filter($rule->category_ids ?? [], 'filled'));

        if ($categories === []) {
            return [$base];
        }

        return array_map(fn (string $categoryId): ProductSearch => $base->category($categoryId), $categories);
    }

    private function matches(ImportRule $rule, ProductSummary $summary): bool
    {
        if (($summary->warehouseInventory ?? 0) < $rule->min_stock) {
            return false;
        }

        $cost = CostParser::lowest($summary->sellPrice);

        if ($rule->min_cost !== null && ($cost === null || bccomp($cost, (string) $rule->min_cost, 2) < 0)) {
            return false;
        }

        if ($rule->max_cost !== null && ($cost === null || bccomp($cost, (string) $rule->max_cost, 2) > 0)) {
            return false;
        }

        return true;
    }

    /**
     * @return 'created'|'updated'|'skipped_ignored'|'already_imported'
     */
    private function upsert(ImportRule $rule, ProductSummary $summary): string
    {
        $candidate = Candidate::query()->firstOrNew(['import_rule_id' => $rule->id, 'cj_product_id' => $summary->id]);

        if ($candidate->exists && $candidate->status === CandidateStatus::Ignored) {
            return 'skipped_ignored';
        }

        $link = ProductLink::query()->where('cj_product_id', $summary->id)->first();
        $isNew = ! $candidate->exists;

        $candidate->fill([
            'cj_sku' => $summary->sku,
            'name' => $summary->name ?? $summary->sku ?? $summary->id,
            'image_url' => $summary->image,
            'cost_usd' => CostParser::lowest($summary->sellPrice),
            'warehouse_stock' => $summary->warehouseInventory,
            'cj_category_id' => $summary->categoryId,
            'payload' => $summary->raw(),
        ]);

        if ($link !== null) {
            $candidate->status = CandidateStatus::Imported;
            $candidate->lunar_product_id = $link->lunar_product_id;
        } elseif ($isNew) {
            $candidate->status = CandidateStatus::Pending;
        }

        if ($isNew) {
            $candidate->discovered_at = now();
        }

        $candidate->save();

        return match (true) {
            $link !== null => 'already_imported',
            $isNew => 'created',
            default => 'updated',
        };
    }
}
```

`src/Jobs/DiscoverCandidatesJob.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Thayron\CjDropshipping\Exceptions\QuotaExceededException;
use Thayron\LunarCjDropshipping\Actions\DiscoverCandidates;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Support\QuotaDelay;

final class DiscoverCandidatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public ImportRule $rule)
    {
        $this->onQueue((string) config('lunar-cjdropshipping.queue'));
    }

    public function uniqueId(): string
    {
        return 'cj-discover-'.$this->rule->id;
    }

    public function handle(DiscoverCandidates $discover): void
    {
        try {
            $discover->handle($this->rule);
        } catch (QuotaExceededException) {
            $this->release(QuotaDelay::seconds());
        }
    }
}
```

`src/Console/DiscoverCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Console;

use Illuminate\Console\Command;
use Thayron\LunarCjDropshipping\Jobs\DiscoverCandidatesJob;
use Thayron\LunarCjDropshipping\Models\ImportRule;

final class DiscoverCommand extends Command
{
    protected $signature = 'cj:discover {--rule=* : Import rule ids (defaults to all active rules)}';

    protected $description = 'Queue CJdropshipping candidate discovery for import rules';

    public function handle(): int
    {
        $ids = array_filter((array) $this->option('rule'));

        $rules = ImportRule::query()
            ->when($ids !== [], fn ($query) => $query->whereKey($ids), fn ($query) => $query->where('is_active', true))
            ->get();

        $rules->each(fn (ImportRule $rule) => DiscoverCandidatesJob::dispatch($rule));

        $this->info("Dispatched {$rules->count()} discovery job(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Registrar no provider**

Em `src/LunarCjDropshippingServiceProvider.php`, adicione ao `register()`:
```php
        $this->app->singleton(Support\Throttle::class, fn () => new Support\Throttle((int) config('lunar-cjdropshipping.requests_per_second', 1)));
```
e ao `boot()`, dentro de `if ($this->app->runningInConsole())`:
```php
            $this->commands([
                Console\DiscoverCommand::class,
            ]);
```

- [ ] **Step 7: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Feature/Discovery`
Expected: `OK (9 tests, ...)`. Depois `vendor/bin/phpunit` → `OK`.

- [ ] **Step 8: Commit**

```bash
git add src tests
git commit -m "feat: discover CJ candidates from import rules" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 6: Importação de imagens

**Files:**
- Create: `src/Media/ImageDownloader.php`, `src/Media/HttpImageDownloader.php`
- Create: `src/Actions/ImportProductImages.php`, `src/Jobs/ImportProductImagesJob.php`
- Modify: `src/LunarCjDropshippingServiceProvider.php` (binding `ImageDownloader`)
- Test: `tests/Feature/Media/ImportProductImagesTest.php`

**Interfaces:**
- Consumes: `ProductLink` (Task 2), `ImportException` (Task 3); `Lunar\Models\Product` (`HasMedia`: `addMedia(string)->withCustomProperties(array)->toMediaCollection(string)`, `getMedia(string)`, `Media::getCustomProperty(string)`).
- Produces: `interface ImageDownloader { public function download(string $url): string; }` (retorna caminho de arquivo temporário); `HttpImageDownloader` via `Illuminate\Support\Facades\Http` (timeout 30s).
- Produces: `Actions\ImportProductImages::__construct(ImageDownloader $downloader)` e `handle(ProductLink $link, array $urls): list<string>` (lista de falhas `"url: mensagem"`; grava `sync_error`).
- Produces: `Jobs\ImportProductImagesJob(ProductLink $link, array $urls)` (`ShouldQueue`, `ShouldBeUnique` por link, `tries = 3`, `backoff = 60`; lança `ImportException` se houver falhas).
- Produces: `Support\MediaCollection::name(): string` — `config('lunar-cjdropshipping.media.collection')` ou `config('lunar.media.collection', 'images')`.

- [ ] **Step 1: Escrever o teste que falha**

`tests/Feature/Media/ImportProductImagesTest.php`:
```php
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
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Feature/Media`
Expected: FAIL — `Class "Thayron\LunarCjDropshipping\Actions\ImportProductImages" not found`.

- [ ] **Step 3: Implementar**

`src/Media/ImageDownloader.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Media;

interface ImageDownloader
{
    /**
     * Download an image and return the path of a local temporary file.
     */
    public function download(string $url): string;
}
```

`src/Media/HttpImageDownloader.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Media;

use Illuminate\Support\Facades\Http;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;

final class HttpImageDownloader implements ImageDownloader
{
    public function download(string $url): string
    {
        $response = Http::timeout(30)->get($url)->throw();

        $extension = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cj-'.bin2hex(random_bytes(8)).'.'.strtolower($extension);

        if (file_put_contents($path, $response->body()) === false) {
            throw new ImportException("Could not write downloaded image [{$url}].");
        }

        return $path;
    }
}
```

`src/Support/MediaCollection.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Support;

final class MediaCollection
{
    public static function name(): string
    {
        $configured = config('lunar-cjdropshipping.media.collection');

        return is_string($configured) && $configured !== '' ? $configured : (string) config('lunar.media.collection', 'images');
    }
}
```

`src/Actions/ImportProductImages.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Thayron\LunarCjDropshipping\Media\ImageDownloader;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\MediaCollection;
use Throwable;

final class ImportProductImages
{
    public function __construct(private readonly ImageDownloader $downloader)
    {
    }

    /**
     * @param  array<array-key, string>  $urls
     * @return list<string> failures as "url: message"
     */
    public function handle(ProductLink $link, array $urls): array
    {
        $product = $link->product;

        if ($product === null) {
            return [];
        }

        $collection = MediaCollection::name();
        $media = $product->getMedia($collection);
        $existing = $media->map(fn ($item) => $item->getCustomProperty('cj_source_url'))->filter()->all();
        $hasPrimary = $media->contains(fn ($item) => (bool) $item->getCustomProperty('primary'));
        $failures = [];

        foreach (array_values(array_unique($urls)) as $url) {
            if (in_array($url, $existing, true)) {
                continue;
            }

            try {
                $path = $this->downloader->download($url);

                $product->addMedia($path)
                    ->withCustomProperties(['cj_source_url' => $url, 'primary' => ! $hasPrimary])
                    ->toMediaCollection($collection);

                $hasPrimary = true;
            } catch (Throwable $exception) {
                $failures[] = "{$url}: {$exception->getMessage()}";
            }
        }

        $link->forceFill(['sync_error' => $failures === [] ? null : 'Image import failed: '.implode('; ', $failures)])->save();

        return $failures;
    }
}
```

`src/Jobs/ImportProductImagesJob.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Thayron\LunarCjDropshipping\Actions\ImportProductImages;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Models\ProductLink;

final class ImportProductImagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  list<string>  $urls
     */
    public function __construct(public ProductLink $link, public array $urls)
    {
        $this->onQueue((string) config('lunar-cjdropshipping.queue'));
    }

    public function uniqueId(): string
    {
        return 'cj-images-'.$this->link->id;
    }

    public function handle(ImportProductImages $images): void
    {
        $failures = $images->handle($this->link, $this->urls);

        if ($failures !== []) {
            throw new ImportException(count($failures).' image(s) failed to import for CJ product '.$this->link->cj_product_id.'.');
        }
    }
}
```

- [ ] **Step 4: Registrar o downloader**

Em `register()` do provider:
```php
        $this->app->bind(Media\ImageDownloader::class, Media\HttpImageDownloader::class);
```

- [ ] **Step 5: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Feature/Media`
Expected: `OK (4 tests, ...)`. Se o media library recusar o arquivo (mime/extensão) ou tentar gerar conversões com driver de imagem, reporte o erro exato (as conversões vão para a conexão `discard` configurada no `TestCase`).

- [ ] **Step 6: Commit**

```bash
git add src tests/Feature/Media
git commit -m "feat: import CJ product images into the Lunar media library" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 7: Importação de produto (OptionResolver, VariantWriter, ImportProduct, job)

**Files:**
- Create: `src/Catalog/OptionResolver.php`, `src/Catalog/VariantWriter.php`
- Create: `src/Actions/ImportProduct.php`, `src/Jobs/ImportProductJob.php`
- Modify: `tests/Support/FakeCj.php` (adicionar `data()`)
- Test: `tests/Feature/Import/ImportProductTest.php`, `tests/Feature/Import/ImportProductJobTest.php`

**Interfaces:**
- Consumes: models/enums (T2), `PriceCalculator`/`CostParser`/exceções (T3), `VariantOptionParser`/`MeasurementConverter`/`StockResolver` (T4), `Throttle`/`QuotaDelay`/`CjLog`/`FakeCj` (T5), `ImportProductImagesJob` (T6); SDK `products()->find(string): Product` (`id`, `name`, `description`, `mainImage`, `images`, `sellPrice`, `variants`, `raw()`), `products()->inventoryByProduct(string): ProductInventory`, `webhooks()->subscribeProducts(array)`, exceções `NotFoundException`, `QuotaExceededException`, `RateLimitException`, `ServerException`, `TransportException`.
- Produces: `Catalog\OptionResolver`:
  - `translated(string $value): array<string, string>` — mesmo texto para cada `Language` (fallback `['en' => $value]`)
  - `option(string $name): ProductOption` — por `handle` = `Str::slug($name)`, cria `shared = true`
  - `value(ProductOption $option, string $name): ProductOptionValue` — por nome na língua padrão
- Produces: `Catalog\VariantWriter`:
  - `create(Product $product, ProductLink $link, CjProduct $cjProduct, CjVariant $cjVariant, list<string> $values, list<ProductOption> $options, ProductInventory $inventory): VariantLink`
  - `writePrices(ProductVariant $variant, string $costUsd, ProductLink $link): void` — `updateOrCreate` por variante + moeda, grupo nulo, `min_quantity` 1
  - `costFor(CjProduct $cjProduct, CjVariant $cjVariant): ?string`
- Produces: `Actions\ImportProduct::handle(Candidate $candidate): ImportResult` onde `ImportResult` = `final readonly class Catalog\ImportResult(ProductLink $link, list<string> $imageUrls)`.
- Produces: `Jobs\ImportProductJob(Candidate $candidate)` (`ShouldQueue`, `ShouldBeUnique` por `cj_product_id`, `tries = 3`, `backoff = [60, 300]`).
- Produces (testes): `FakeCj::data(string $name): array` (o `data` da fixture).

- [ ] **Step 1: Adicionar `FakeCj::data()`**

Em `tests/Support/FakeCj.php`:
```php
    /**
     * @return array<string, mixed>
     */
    public static function data(string $name): array
    {
        $envelope = json_decode((string) file_get_contents(__DIR__."/../Fixtures/{$name}.json"), true);

        return $envelope['data'];
    }
```

- [ ] **Step 2: Escrever os testes que falham**

`tests/Feature/Import/ImportProductTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Import;

use Lunar\Models\Collection;
use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Thayron\LunarCjDropshipping\Actions\ImportProduct;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Exceptions\PricingException;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ImportProductTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cj = FakeCj::install($this->app);
    }

    public function test_imports_a_draft_product_with_options_variants_prices_and_stock(): void
    {
        $this->createLunarBaseline();
        $collection = Collection::factory()->create();
        $candidate = $this->candidate(['country_code' => 'US', 'collection_id' => $collection->id]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        $result = app(ImportProduct::class)->handle($candidate);

        $this->assertSame(['product/query', 'product/stock/getInventoryByPid'], $this->cj->paths());

        $product = Product::query()->sole();
        $this->assertSame('draft', $product->status);
        $this->assertSame('Magnetic Phone Case', $product->translateAttribute('name', 'en'));
        $this->assertSame('Magnetic Phone Case', $product->translateAttribute('name', 'fr'));
        $this->assertSame('<p>Strong magnets</p>', $product->translateAttribute('description', 'en'));
        $this->assertTrue($product->collections->contains($collection));
        $this->assertTrue($product->channels->contains($this->channel));
        $this->assertSame(['color', 'size'], $product->productOptions()->orderByPivot('position')->pluck('handle')->all());

        $black = ProductVariant::query()->where('sku', 'CJ-CASE-BLK-XL')->sole();
        $navy = ProductVariant::query()->where('sku', 'CJ-CASE-NVY-M')->sole();
        $this->assertSame(7, (int) $black->stock);
        $this->assertSame(0, (int) $navy->stock);
        $this->assertSame('in_stock', $black->purchasable);
        $this->assertEqualsWithDelta(1.58, (float) $black->weight_value, 0.0001);
        $this->assertSame('kg', $black->weight_unit);
        $this->assertEqualsWithDelta(30.0, (float) $black->length_value, 0.0001);
        $this->assertSame('cm', $black->length_unit);
        $this->assertSame(['Black', 'XL'], $black->values->sortBy('product_option_id')->map(fn ($value) => $value->name['en'])->values()->all());
        $this->assertSame(['Navy Blue', 'M'], $navy->values->sortBy('product_option_id')->map(fn ($value) => $value->name['en'])->values()->all());

        $this->assertSame(['EUR' => 1890, 'GBP' => 1590, 'USD' => 2090], $this->prices($black));
        $this->assertSame(['EUR' => 1590, 'GBP' => 1290, 'USD' => 1690], $this->prices($navy));

        $link = ProductLink::query()->sole();
        $this->assertTrue($result->link->is($link));
        $this->assertSame($product->id, $link->lunar_product_id);
        $this->assertSame('100.00', $link->markup_percent);
        $this->assertSame(PriceRounding::Ends90, $link->rounding);
        $this->assertSame('US', $link->country_code);
        $this->assertSame(CjProductStatus::Active, $link->cj_status);
        $this->assertSame(['https://cf.cjdropshipping.com/case-1.jpg', 'https://cf.cjdropshipping.com/case-2.jpg'], $result->imageUrls);

        $variantLink = VariantLink::query()->where('cj_variant_id', 'v-1')->sole();
        $this->assertSame($black->id, $variantLink->lunar_variant_id);
        $this->assertSame('10.00', $variantLink->cost_usd);
        $this->assertSame(7, $variantLink->stock);

        $candidate->refresh();
        $this->assertSame(CandidateStatus::Imported, $candidate->status);
        $this->assertSame($product->id, $candidate->lunar_product_id);
    }

    public function test_uses_total_stock_when_the_rule_has_no_country(): void
    {
        $this->createLunarBaseline();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        app(ImportProduct::class)->handle($this->candidate());

        $this->assertSame(107, (int) ProductVariant::query()->where('sku', 'CJ-CASE-BLK-XL')->value('stock'));
        $this->assertSame(20, (int) ProductVariant::query()->where('sku', 'CJ-CASE-NVY-M')->value('stock'));
    }

    public function test_reuses_shared_options_and_values_across_products(): void
    {
        $this->createLunarBaseline();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');
        app(ImportProduct::class)->handle($this->candidate());

        $second = FakeCj::data('product-detail');
        $second['pid'] = 'p-300';
        $second['variants'][0]['vid'] = 'v-31';
        $second['variants'][1]['vid'] = 'v-32';
        $this->cj->success($second)->fixture('stock-by-pid');

        app(ImportProduct::class)->handle($this->candidate(cjProductId: 'p-300'));

        $this->assertSame(2, \Lunar\Models\ProductOption::query()->count());
        $this->assertSame(4, \Lunar\Models\ProductOptionValue::query()->count());
    }

    public function test_single_variant_products_have_no_options(): void
    {
        $this->createLunarBaseline();
        $data = FakeCj::data('product-detail');
        $data['variants'] = [$data['variants'][0]];
        $this->cj->success($data)->fixture('stock-by-pid');

        app(ImportProduct::class)->handle($this->candidate());

        $product = Product::query()->sole();
        $this->assertSame(0, $product->productOptions()->count());
        $this->assertSame(1, $product->variants()->count());
    }

    public function test_is_idempotent_when_the_product_is_already_linked(): void
    {
        $this->createLunarBaseline();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');
        $first = app(ImportProduct::class)->handle($this->candidate());

        $again = app(ImportProduct::class)->handle($this->candidate());

        $this->assertTrue($again->link->is($first->link));
        $this->assertSame([], $again->imageUrls);
        $this->assertSame(1, Product::query()->count());
        $this->assertCount(2, $this->cj->requests());
    }

    public function test_rolls_back_everything_when_pricing_fails(): void
    {
        $this->createLunarBaseline(withUsd: false);
        config(['lunar-cjdropshipping.pricing.usd_to_default_rate' => null]);
        $candidate = $this->candidate();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        try {
            app(ImportProduct::class)->handle($candidate);
            $this->fail('Expected PricingException.');
        } catch (PricingException) {
            $this->assertSame(0, Product::withTrashed()->count());
            $this->assertSame(0, ProductLink::query()->count());
            $this->assertSame(0, VariantLink::query()->count());
        }
    }

    public function test_requires_a_default_tax_class(): void
    {
        $this->createLunarBaseline();
        TaxClass::query()->update(['default' => false]);

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('default tax class');

        app(ImportProduct::class)->handle($this->candidate());
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

    /**
     * @param  array<string, mixed>  $ruleAttributes
     */
    private function candidate(array $ruleAttributes = [], string $cjProductId = 'p-100'): Candidate
    {
        $rule = ImportRule::query()->firstOrCreate(['name' => 'Rule'], [
            'keyword' => 'case',
            'markup_percent' => '100',
            'rounding' => PriceRounding::Ends90,
            'product_type_id' => $this->productType->id,
            ...$ruleAttributes,
        ]);

        return Candidate::query()->firstOrCreate(
            ['import_rule_id' => $rule->id, 'cj_product_id' => $cjProductId],
            ['name' => 'Magnetic Phone Case', 'status' => CandidateStatus::Approved, 'payload' => [], 'discovered_at' => now()],
        );
    }
}
```

`tests/Feature/Import/ImportProductJobTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Import;

use Illuminate\Support\Facades\Bus;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\LunarCjDropshipping\Actions\ImportProduct;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Jobs\ImportProductImagesJob;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ImportProductJobTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cjdropshipping.max_retries' => 0]);
        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);

        $rule = ImportRule::create(['name' => 'Rule', 'keyword' => 'case', 'markup_percent' => '100', 'rounding' => PriceRounding::Ends90, 'product_type_id' => $this->productType->id]);
        $this->candidate = Candidate::create(['import_rule_id' => $rule->id, 'cj_product_id' => 'p-100', 'name' => 'Case', 'status' => CandidateStatus::Approved, 'payload' => [], 'discovered_at' => now()]);
    }

    public function test_imports_then_queues_images_and_subscribes_the_product(): void
    {
        Bus::fake([ImportProductImagesJob::class]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid')
            ->success(['successProductIds' => ['p-100'], 'failProductIds' => [], 'subscribeAll' => false]);

        $this->runJob();

        $this->assertSame(CandidateStatus::Imported, $this->candidate->fresh()->status);
        Bus::assertDispatched(ImportProductImagesJob::class, fn (ImportProductImagesJob $job) => count($job->urls) === 2);
        $this->assertSame('webhook/product/subscribe', $this->cj->paths()[2]);
        $this->assertSame(['productIds' => ['p-100']], $this->cj->jsonAt(2));
    }

    public function test_marks_unavailable_products_as_failed_without_retrying(): void
    {
        $this->cj->error(1602001, 'Product not found');

        $this->runJob();

        $candidate = $this->candidate->fresh();
        $this->assertSame(CandidateStatus::Failed, $candidate->status);
        $this->assertSame('Product is no longer available on CJdropshipping.', $candidate->error);
    }

    public function test_marks_transient_errors_as_failed_and_rethrows(): void
    {
        $this->cj->error(1600000, 'System busy');

        try {
            $this->runJob();
            $this->fail('Expected ServerException.');
        } catch (ServerException) {
            $candidate = $this->candidate->fresh();
            $this->assertSame(CandidateStatus::Failed, $candidate->status);
            $this->assertStringContainsString('System busy', (string) $candidate->error);
        }
    }

    public function test_releases_until_tomorrow_on_quota_errors(): void
    {
        $this->cj->error(1600201, 'Daily quota exhausted');

        $job = (new ImportProductJob($this->candidate))->withFakeQueueInteractions();
        $job->handle(app(ImportProduct::class), app(CjClient::class), app(Throttle::class));

        $job->assertReleased();
        $this->assertSame(CandidateStatus::Approved, $this->candidate->fresh()->status);
    }

    public function test_a_failed_webhook_subscription_does_not_fail_the_import(): void
    {
        Bus::fake([ImportProductImagesJob::class]);
        $this->cj->fixture('product-detail')->fixture('stock-by-pid')->error(1600000, 'System busy');

        $this->runJob();

        $this->assertSame(CandidateStatus::Imported, $this->candidate->fresh()->status);
    }

    private function runJob(): void
    {
        (new ImportProductJob($this->candidate))->handle(app(ImportProduct::class), app(CjClient::class), app(Throttle::class));
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Feature/Import`
Expected: FAIL — `Class "Thayron\LunarCjDropshipping\Actions\ImportProduct" not found`.

- [ ] **Step 4: Implementar Catalog**

`src/Catalog/ImportResult.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Catalog;

use Thayron\LunarCjDropshipping\Models\ProductLink;

final readonly class ImportResult
{
    /**
     * @param  list<string>  $imageUrls  images to download (empty when the product was already linked)
     */
    public function __construct(
        public ProductLink $link,
        public array $imageUrls,
    ) {
    }
}
```

`src/Catalog/OptionResolver.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Catalog;

use Illuminate\Support\Str;
use Lunar\Models\Language;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductOptionValue;

final class OptionResolver
{
    /**
     * @return array<string, string>
     */
    public function translated(string $value): array
    {
        $codes = Language::query()->orderBy('id')->pluck('code')->all();

        return $codes === [] ? ['en' => $value] : array_fill_keys($codes, $value);
    }

    public function option(string $name): ProductOption
    {
        return ProductOption::query()->firstOrCreate(
            ['handle' => Str::slug($name)],
            ['name' => $this->translated($name), 'label' => $this->translated($name), 'shared' => true],
        );
    }

    public function value(ProductOption $option, string $name): ProductOptionValue
    {
        $locale = Language::getDefault()?->code ?? 'en';

        $existing = $option->values()->get()->first(
            fn (ProductOptionValue $value): bool => ($value->name[$locale] ?? null) === $name,
        );

        return $existing ?? $option->values()->create([
            'name' => $this->translated($name),
            'position' => $option->values()->count() + 1,
        ]);
    }
}
```

`src/Catalog/VariantWriter.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Catalog;

use Lunar\Models\Price;
use Lunar\Models\Product;
use Lunar\Models\ProductOption;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Thayron\CjDropshipping\Data\Product as CjProduct;
use Thayron\CjDropshipping\Data\ProductInventory;
use Thayron\CjDropshipping\Data\Variant as CjVariant;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Mapping\MeasurementConverter;
use Thayron\LunarCjDropshipping\Mapping\StockResolver;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Pricing\CostParser;
use Thayron\LunarCjDropshipping\Pricing\PriceCalculator;

final class VariantWriter
{
    public function __construct(
        private readonly OptionResolver $options,
        private readonly PriceCalculator $prices,
        private readonly StockResolver $stock,
        private readonly MeasurementConverter $measurements,
    ) {
    }

    /**
     * @param  list<string>  $values  option values in the same order as $options
     * @param  list<ProductOption>  $options
     */
    public function create(Product $product, ProductLink $link, CjProduct $cjProduct, CjVariant $cjVariant, array $values, array $options, ProductInventory $inventory): VariantLink
    {
        $taxClass = TaxClass::getDefault() ?? throw new ImportException('Lunar has no default tax class.');
        $stock = $this->stock->forVariant($inventory, $cjVariant->id, $link->country_code);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'tax_class_id' => $taxClass->id,
            'sku' => $cjVariant->sku,
            'unit_quantity' => 1,
            'purchasable' => 'in_stock',
            'shippable' => true,
            'backorder' => 0,
            'stock' => $stock,
            'weight_value' => $this->measurements->gramsToKilograms($cjVariant->weight),
            'weight_unit' => 'kg',
            'length_value' => $this->measurements->millimetersToCentimeters($cjVariant->length),
            'length_unit' => 'cm',
            'width_value' => $this->measurements->millimetersToCentimeters($cjVariant->width),
            'width_unit' => 'cm',
            'height_value' => $this->measurements->millimetersToCentimeters($cjVariant->height),
            'height_unit' => 'cm',
        ]);

        foreach ($values as $index => $value) {
            if (isset($options[$index])) {
                $variant->values()->attach($this->options->value($options[$index], $value)->id);
            }
        }

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
    }

    public function writePrices(ProductVariant $variant, string $costUsd, ProductLink $link): void
    {
        $amounts = $this->prices->pricesFor($costUsd, (string) $link->markup_percent, $link->rounding);

        foreach ($amounts as $currencyId => $amount) {
            Price::query()->updateOrCreate([
                'priceable_type' => $variant->getMorphClass(),
                'priceable_id' => $variant->id,
                'currency_id' => $currencyId,
                'customer_group_id' => null,
                'min_quantity' => 1,
            ], ['price' => $amount]);
        }
    }

    public function costFor(CjProduct $cjProduct, CjVariant $cjVariant): ?string
    {
        return CostParser::lowest($cjVariant->sellPrice) ?? CostParser::lowest($cjProduct->sellPrice);
    }
}
```

- [ ] **Step 5: Implementar ImportProduct e o job**

`src/Actions/ImportProduct.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Support\Facades\DB;
use Lunar\FieldTypes\Text;
use Lunar\FieldTypes\TranslatedText;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Product;
use Lunar\Models\TaxClass;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Data\Product as CjProduct;
use Thayron\CjDropshipping\Data\ProductInventory;
use Thayron\LunarCjDropshipping\Catalog\ImportResult;
use Thayron\LunarCjDropshipping\Catalog\OptionResolver;
use Thayron\LunarCjDropshipping\Catalog\VariantWriter;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Mapping\VariantOptionParser;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\Throttle;

final class ImportProduct
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
        private readonly VariantOptionParser $parser,
        private readonly OptionResolver $options,
        private readonly VariantWriter $variants,
    ) {
    }

    public function handle(Candidate $candidate): ImportResult
    {
        $existing = ProductLink::query()->where('cj_product_id', $candidate->cj_product_id)->first();

        if ($existing !== null) {
            $candidate->forceFill(['status' => CandidateStatus::Imported, 'lunar_product_id' => $existing->lunar_product_id, 'error' => null])->save();

            return new ImportResult($existing, []);
        }

        $this->assertStoreIsReady();
        $candidate->forceFill(['status' => CandidateStatus::Importing, 'error' => null])->save();

        $this->throttle->wait();
        $cjProduct = $this->cj->products()->find($candidate->cj_product_id);
        $this->throttle->wait();
        $inventory = $this->cj->products()->inventoryByProduct($cjProduct->id);

        $link = DB::transaction(fn (): ProductLink => $this->createProduct($candidate->importRule, $cjProduct, $inventory));

        $candidate->forceFill(['status' => CandidateStatus::Imported, 'lunar_product_id' => $link->lunar_product_id, 'error' => null])->save();

        $imageUrls = $cjProduct->images !== [] ? $cjProduct->images : array_filter([$cjProduct->mainImage]);

        return new ImportResult($link, array_values($imageUrls));
    }

    private function createProduct(ImportRule $rule, CjProduct $cjProduct, ProductInventory $inventory): ProductLink
    {
        if ($cjProduct->variants === []) {
            throw new ImportException("CJ product {$cjProduct->id} has no variants.");
        }

        $product = Product::create([
            'product_type_id' => $rule->product_type_id,
            'brand_id' => $rule->brand_id,
            'status' => 'draft',
            'attribute_data' => collect([
                'name' => $this->translatedText($cjProduct->name ?? $cjProduct->id),
                'description' => $this->translatedText($cjProduct->description ?? ''),
            ]),
        ]);

        $product->scheduleChannel(Channel::getDefault());

        if ($rule->collection_id !== null) {
            $product->collections()->attach($rule->collection_id, ['position' => 1]);
        }

        $link = ProductLink::create([
            'cj_product_id' => $cjProduct->id,
            'lunar_product_id' => $product->id,
            'import_rule_id' => $rule->id,
            'markup_percent' => $rule->markup_percent,
            'rounding' => $rule->rounding,
            'country_code' => $rule->country_code !== null ? strtoupper($rule->country_code) : null,
            'cj_status' => CjProductStatus::Active,
            'new_cj_variant_ids' => [],
            'last_synced_at' => now(),
        ]);

        $raw = $cjProduct->raw();
        $parsed = $this->parser->parse($raw['productKeyEn'] ?? null, $cjProduct->variants);
        $optionModels = array_map(fn (string $name) => $this->options->option($name), $parsed['options']);

        foreach ($optionModels as $position => $option) {
            $product->productOptions()->attach($option->id, ['position' => $position + 1]);
        }

        foreach ($cjProduct->variants as $cjVariant) {
            $this->variants->create($product, $link, $cjProduct, $cjVariant, $parsed['values'][$cjVariant->id] ?? [], $optionModels, $inventory);
        }

        return $link;
    }

    private function translatedText(string $value): TranslatedText
    {
        return new TranslatedText(collect($this->options->translated($value))->map(fn (string $text) => new Text($text)));
    }

    private function assertStoreIsReady(): void
    {
        if (Currency::getDefault() === null) {
            throw new ImportException('Lunar has no default currency.');
        }

        if (TaxClass::getDefault() === null) {
            throw new ImportException('Lunar has no default tax class.');
        }

        if (Channel::getDefault() === null) {
            throw new ImportException('Lunar has no default channel.');
        }
    }
}
```

`src/Jobs/ImportProductJob.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Exceptions\NotFoundException;
use Thayron\CjDropshipping\Exceptions\QuotaExceededException;
use Thayron\CjDropshipping\Exceptions\RateLimitException;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\CjDropshipping\Exceptions\TransportException;
use Thayron\LunarCjDropshipping\Actions\ImportProduct;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Support\CjLog;
use Thayron\LunarCjDropshipping\Support\QuotaDelay;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Throwable;

final class ImportProductJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public Candidate $candidate)
    {
        $this->onQueue((string) config('lunar-cjdropshipping.queue'));
    }

    public function uniqueId(): string
    {
        return 'cj-import-'.$this->candidate->cj_product_id;
    }

    public function handle(ImportProduct $import, CjClient $cj, Throttle $throttle): void
    {
        $candidate = $this->candidate;

        try {
            $result = $import->handle($candidate);
        } catch (QuotaExceededException) {
            $candidate->forceFill(['status' => CandidateStatus::Approved])->save();
            $this->release(QuotaDelay::seconds());

            return;
        } catch (NotFoundException) {
            $this->markFailed($candidate, 'Product is no longer available on CJdropshipping.');

            return;
        } catch (RateLimitException|ServerException|TransportException $exception) {
            $this->markFailed($candidate, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed($candidate, $exception->getMessage());
            CjLog::channel()->error('CJ import failed', ['cj_product_id' => $candidate->cj_product_id, 'exception' => $exception]);

            return;
        }

        if ($result->imageUrls !== []) {
            ImportProductImagesJob::dispatch($result->link, $result->imageUrls);
        }

        try {
            $throttle->wait();
            $cj->webhooks()->subscribeProducts([$result->link->cj_product_id]);
        } catch (Throwable $exception) {
            CjLog::channel()->warning('CJ webhook subscription failed', ['cj_product_id' => $result->link->cj_product_id, 'message' => $exception->getMessage()]);
        }
    }

    private function markFailed(Candidate $candidate, string $message): void
    {
        $candidate->forceFill(['status' => CandidateStatus::Failed, 'error' => $message])->save();
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Feature/Import`
Expected: `OK (12 tests, ...)`. Depois `vendor/bin/phpunit` → `OK`.

Se `Product::translateAttribute`, `orderByPivot` ou o cast de `Price::price` (`->value`) não existirem com essas assinaturas no Lunar 1.4, ajuste só o teste para a API real (ex.: `$product->attr('name')`) e registre no relatório.

- [ ] **Step 7: Commit**

```bash
git add src tests
git commit -m "feat: import approved CJ candidates as draft Lunar products" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 8: Sincronização (SyncProduct, job, `cj:sync`)

**Files:**
- Create: `src/Actions/SyncProduct.php`, `src/Jobs/SyncProductJob.php`, `src/Console/SyncCommand.php`
- Modify: `src/LunarCjDropshippingServiceProvider.php` (registrar `SyncCommand`)
- Create: `tests/Support/ImportsFixtureProduct.php`
- Test: `tests/Feature/Sync/SyncProductTest.php`, `tests/Feature/Sync/SyncCommandTest.php`

**Interfaces:**
- Consumes: T2 models/enums; `VariantWriter::writePrices()`/`costFor()` (T7); `StockResolver` (T4); `Throttle`/`QuotaDelay`/`CjLog`/`FakeCj` (T5); `ImportProduct` (T7, só nos testes); SDK `find()`, `inventoryByProduct()`, exceções.
- Produces: `Actions\SyncProduct::handle(ProductLink $link): void`.
- Produces: `Jobs\SyncProductJob(ProductLink $link)` (`ShouldQueue`, `ShouldBeUnique` por `cj_product_id`, `tries = 3`, `backoff = [60, 300]`).
- Produces: comando `cj:sync {--all} {--product=}` com saída `Dispatched {n} sync job(s).`
- Produces (testes): trait `Tests\Support\ImportsFixtureProduct` com `importFixtureProduct(array $ruleAttributes = []): ProductLink` (usa `$this->cj`, baseline já criado).

- [ ] **Step 1: Criar o helper de testes**

`tests/Support/ImportsFixtureProduct.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Support;

use Thayron\LunarCjDropshipping\Actions\ImportProduct;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;

/**
 * Requires CreatesLunarBaseline (already created) and a FakeCj instance in $this->cj.
 */
trait ImportsFixtureProduct
{
    /**
     * @param  array<string, mixed>  $ruleAttributes
     */
    protected function importFixtureProduct(array $ruleAttributes = []): ProductLink
    {
        $rule = ImportRule::create([
            'name' => 'Rule',
            'keyword' => 'case',
            'markup_percent' => '100',
            'rounding' => PriceRounding::Ends90,
            'product_type_id' => $this->productType->id,
            ...$ruleAttributes,
        ]);

        $candidate = Candidate::create([
            'import_rule_id' => $rule->id,
            'cj_product_id' => 'p-100',
            'name' => 'Magnetic Phone Case',
            'status' => CandidateStatus::Approved,
            'payload' => [],
            'discovered_at' => now(),
        ]);

        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        return app(ImportProduct::class)->handle($candidate)->link;
    }
}
```

- [ ] **Step 2: Escrever os testes que falham**

`tests/Feature/Sync/SyncProductTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Sync;

use Lunar\Models\Price;
use Lunar\Models\ProductVariant;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\LunarCjDropshipping\Actions\SyncProduct;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\Support\ImportsFixtureProduct;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class SyncProductTest extends TestCase
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
        $this->travel(1)->hours();
    }

    public function test_updates_stock_and_recalculates_prices_when_cost_changes(): void
    {
        $product = $this->link->product;
        $product->attribute_data->put('name', new \Lunar\FieldTypes\TranslatedText(collect(['en' => new \Lunar\FieldTypes\Text('Edited name')])));
        $product->save();

        $detail = FakeCj::data('product-detail');
        $detail['productNameEn'] = 'Renamed on CJ';
        $detail['variants'][0]['variantSellPrice'] = '12.00';
        $stock = FakeCj::data('stock-by-pid');
        $stock['variantInventories'][0]['inventory'] = [['countryCode' => 'CN', 'totalInventory' => 50]];
        $this->cj->success($detail)->success($stock);

        app(SyncProduct::class)->handle($this->link);

        $black = $this->variant('CJ-CASE-BLK-XL');
        $this->assertSame(50, (int) $black->stock);
        $this->assertSame(['EUR' => 2290, 'GBP' => 1890, 'USD' => 2490], $this->prices($black));
        $this->assertSame(['EUR' => 1590, 'GBP' => 1290, 'USD' => 1690], $this->prices($this->variant('CJ-CASE-NVY-M')));
        $this->assertSame('12.00', VariantLink::query()->where('cj_variant_id', 'v-1')->value('cost_usd'));
        $this->assertSame('Edited name', $product->fresh()->translateAttribute('name', 'en'));

        $link = $this->link->fresh();
        $this->assertTrue($link->last_synced_at->isSameSecond(now()));
        $this->assertNull($link->sync_error);
    }

    public function test_variant_missing_on_cj_gets_zero_stock_and_new_variants_are_only_recorded(): void
    {
        $detail = FakeCj::data('product-detail');
        $newVariant = $detail['variants'][1];
        $newVariant['vid'] = 'v-3';
        $newVariant['variantSku'] = 'CJ-CASE-RED-S';
        $newVariant['variantKey'] = 'Red-S';
        $detail['variants'] = [$detail['variants'][0], $newVariant];
        $this->cj->success($detail)->fixture('stock-by-pid');

        app(SyncProduct::class)->handle($this->link);

        $this->assertSame(0, (int) $this->variant('CJ-CASE-NVY-M')->stock);
        $this->assertSame(['v-3'], $this->link->fresh()->new_cj_variant_ids);
        $this->assertSame(0, ProductVariant::query()->where('sku', 'CJ-CASE-RED-S')->count());
    }

    public function test_marks_the_product_unavailable_after_consecutive_not_found_responses(): void
    {
        $this->cj->error(1602001, 'Product not found')->error(1602001, 'Product not found');

        app(SyncProduct::class)->handle($this->link);

        $link = $this->link->fresh();
        $this->assertSame(1, $link->not_found_count);
        $this->assertSame(CjProductStatus::Active, $link->cj_status);
        $this->assertSame(107, (int) $this->variant('CJ-CASE-BLK-XL')->stock);

        app(SyncProduct::class)->handle($link);

        $link->refresh();
        $this->assertSame(CjProductStatus::Unavailable, $link->cj_status);
        $this->assertSame(0, (int) $this->variant('CJ-CASE-BLK-XL')->stock);
        $this->assertSame(0, (int) $this->variant('CJ-CASE-NVY-M')->stock);
        $this->assertSame('draft', $link->product->status);
    }

    public function test_unavailable_action_draft_unpublishes_the_product(): void
    {
        config(['lunar-cjdropshipping.sync.unavailable_action' => 'draft', 'lunar-cjdropshipping.sync.not_found_threshold' => 1]);
        $this->link->product->update(['status' => 'published']);
        $this->cj->error(1602001, 'Product not found');

        app(SyncProduct::class)->handle($this->link);

        $this->assertSame('draft', $this->link->product->fresh()->status);
    }

    public function test_out_of_stock_action_keeps_the_product_published(): void
    {
        config(['lunar-cjdropshipping.sync.not_found_threshold' => 1]);
        $this->link->product->update(['status' => 'published']);
        $this->cj->error(1602001, 'Product not found');

        app(SyncProduct::class)->handle($this->link);

        $this->assertSame('published', $this->link->product->fresh()->status);
        $this->assertSame(CjProductStatus::Unavailable, $this->link->fresh()->cj_status);
    }

    public function test_a_successful_sync_resets_not_found_count_and_reactivates(): void
    {
        $this->link->forceFill(['not_found_count' => 1, 'cj_status' => CjProductStatus::Unavailable])->save();
        $this->cj->fixture('product-detail')->fixture('stock-by-pid');

        app(SyncProduct::class)->handle($this->link);

        $link = $this->link->fresh();
        $this->assertSame(0, $link->not_found_count);
        $this->assertSame(CjProductStatus::Active, $link->cj_status);
    }

    public function test_job_records_transient_errors_without_advancing_last_synced_at(): void
    {
        $before = $this->link->fresh()->last_synced_at;
        $this->cj->error(1600000, 'System busy');

        try {
            (new SyncProductJob($this->link))->handle(app(SyncProduct::class));
            $this->fail('Expected ServerException.');
        } catch (ServerException) {
            $link = $this->link->fresh();
            $this->assertStringContainsString('System busy', (string) $link->sync_error);
            $this->assertTrue($link->last_synced_at->equalTo($before));
        }
    }

    public function test_job_releases_on_quota_errors(): void
    {
        $this->cj->error(1600201, 'Daily quota exhausted');

        $job = (new SyncProductJob($this->link))->withFakeQueueInteractions();
        $job->handle(app(SyncProduct::class));

        $job->assertReleased();
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

`tests/Feature/Sync/SyncCommandTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Sync;

use Illuminate\Support\Facades\Queue;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class SyncCommandTest extends TestCase
{
    use CreatesLunarBaseline;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createLunarBaseline();
    }

    public function test_dispatches_only_stale_links_by_default(): void
    {
        $stale = $this->link('p-1', now()->subHours(7));
        $never = $this->link('p-2', null);
        $this->link('p-3', now()->subHour());

        $this->artisan('cj:sync')->expectsOutput('Dispatched 2 sync job(s).')->assertSuccessful();

        Queue::assertPushed(SyncProductJob::class, 2);
        Queue::assertPushed(SyncProductJob::class, fn (SyncProductJob $job) => $job->link->is($stale));
        Queue::assertPushed(SyncProductJob::class, fn (SyncProductJob $job) => $job->link->is($never));
    }

    public function test_all_option_dispatches_every_link(): void
    {
        $this->link('p-1', now());
        $this->link('p-2', now());

        $this->artisan('cj:sync --all')->expectsOutput('Dispatched 2 sync job(s).')->assertSuccessful();
    }

    public function test_product_option_dispatches_a_single_link(): void
    {
        $this->link('p-1', now());
        $target = $this->link('p-2', now());

        $this->artisan('cj:sync --product=p-2')->expectsOutput('Dispatched 1 sync job(s).')->assertSuccessful();

        Queue::assertPushed(SyncProductJob::class, fn (SyncProductJob $job) => $job->link->is($target));
    }

    private function link(string $cjProductId, ?\DateTimeInterface $lastSyncedAt): ProductLink
    {
        return ProductLink::create([
            'cj_product_id' => $cjProductId,
            'lunar_product_id' => Product::factory()->create(['product_type_id' => $this->productType->id])->id,
            'markup_percent' => '0',
            'rounding' => PriceRounding::None,
            'new_cj_variant_ids' => [],
            'last_synced_at' => $lastSyncedAt,
        ]);
    }
}
```

Nota sobre os valores: custo novo 12,00 USD × 2 = 24 → EUR 22,2222 → 22,90; GBP 18,8889 → 18,90; USD 23,99999999997 → 24,90. O `ImportProduct` usa `status = draft`, então o teste de indisponibilidade padrão (`out_of_stock`) mantém `draft` porque o produto nunca foi publicado.

- [ ] **Step 3: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Feature/Sync`
Expected: FAIL — `Class "Thayron\LunarCjDropshipping\Actions\SyncProduct" not found`.

- [ ] **Step 4: Implementar**

`src/Actions/SyncProduct.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Support\Facades\DB;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Data\Variant as CjVariant;
use Thayron\CjDropshipping\Exceptions\NotFoundException;
use Thayron\LunarCjDropshipping\Catalog\VariantWriter;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Enums\UnavailableAction;
use Thayron\LunarCjDropshipping\Mapping\StockResolver;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\Throttle;

/**
 * Syncs stock, cost-based prices and availability. Never touches names, descriptions, images or options.
 */
final class SyncProduct
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
        private readonly StockResolver $stock,
        private readonly VariantWriter $variants,
    ) {
    }

    public function handle(ProductLink $link): void
    {
        try {
            $this->throttle->wait();
            $cjProduct = $this->cj->products()->find($link->cj_product_id);
        } catch (NotFoundException) {
            $this->recordNotFound($link);

            return;
        }

        $this->throttle->wait();
        $inventory = $this->cj->products()->inventoryByProduct($link->cj_product_id);

        /** @var array<string, CjVariant> $cjVariants */
        $cjVariants = collect($cjProduct->variants)->keyBy(fn (CjVariant $variant) => $variant->id)->all();

        DB::transaction(function () use ($link, $cjProduct, $cjVariants, $inventory): void {
            foreach ($link->variantLinks()->with('variant')->get() as $variantLink) {
                $variant = $variantLink->variant;

                if ($variant === null) {
                    continue;
                }

                $cjVariant = $cjVariants[$variantLink->cj_variant_id] ?? null;

                if ($cjVariant === null) {
                    $variant->update(['stock' => 0]);
                    $variantLink->update(['stock' => 0, 'last_synced_at' => now()]);

                    continue;
                }

                $stock = $this->stock->forVariant($inventory, $cjVariant->id, $link->country_code);
                $cost = $this->variants->costFor($cjProduct, $cjVariant);
                $variant->update(['stock' => $stock]);

                if ($cost !== null && ($variantLink->cost_usd === null || bccomp($cost, (string) $variantLink->cost_usd, 2) !== 0)) {
                    $this->variants->writePrices($variant, $cost, $link);
                }

                $variantLink->update(['stock' => $stock, 'cost_usd' => $cost ?? $variantLink->cost_usd, 'last_synced_at' => now()]);
            }

            $known = $link->variantLinks()->pluck('cj_variant_id')->all();

            $link->forceFill([
                'not_found_count' => 0,
                'cj_status' => CjProductStatus::Active,
                'new_cj_variant_ids' => array_values(array_diff(array_keys($cjVariants), $known)),
                'last_synced_at' => now(),
                'sync_error' => null,
            ])->save();
        });
    }

    private function recordNotFound(ProductLink $link): void
    {
        $count = $link->not_found_count + 1;
        $link->forceFill(['not_found_count' => $count])->save();

        if ($count < (int) config('lunar-cjdropshipping.sync.not_found_threshold', 2)) {
            return;
        }

        DB::transaction(function () use ($link): void {
            foreach ($link->variantLinks()->with('variant')->get() as $variantLink) {
                $variantLink->variant?->update(['stock' => 0]);
                $variantLink->update(['stock' => 0, 'last_synced_at' => now()]);
            }

            if (config('lunar-cjdropshipping.sync.unavailable_action') === UnavailableAction::Draft->value) {
                $link->product?->update(['status' => 'draft']);
            }

            $link->forceFill(['cj_status' => CjProductStatus::Unavailable, 'last_synced_at' => now(), 'sync_error' => null])->save();
        });
    }
}
```

`src/Jobs/SyncProductJob.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Thayron\CjDropshipping\Exceptions\QuotaExceededException;
use Thayron\CjDropshipping\Exceptions\RateLimitException;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\CjDropshipping\Exceptions\TransportException;
use Thayron\LunarCjDropshipping\Actions\SyncProduct;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\CjLog;
use Thayron\LunarCjDropshipping\Support\QuotaDelay;
use Throwable;

final class SyncProductJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public ProductLink $link)
    {
        $this->onQueue((string) config('lunar-cjdropshipping.queue'));
    }

    public function uniqueId(): string
    {
        return 'cj-sync-'.$this->link->cj_product_id;
    }

    public function handle(SyncProduct $sync): void
    {
        try {
            $sync->handle($this->link);
        } catch (QuotaExceededException) {
            $this->release(QuotaDelay::seconds());
        } catch (RateLimitException|ServerException|TransportException $exception) {
            $this->link->forceFill(['sync_error' => $exception->getMessage()])->save();

            throw $exception;
        } catch (Throwable $exception) {
            $this->link->forceFill(['sync_error' => $exception->getMessage()])->save();
            CjLog::channel()->error('CJ sync failed', ['cj_product_id' => $this->link->cj_product_id, 'exception' => $exception]);
        }
    }
}
```

`src/Console/SyncCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;

final class SyncCommand extends Command
{
    protected $signature = 'cj:sync
        {--all : Sync every linked product}
        {--product= : Sync a single CJ product id}';

    protected $description = 'Queue CJdropshipping stock and price sync for linked products';

    public function handle(): int
    {
        $query = ProductLink::query();
        $productId = $this->option('product');

        if (is_string($productId) && $productId !== '') {
            $query->where('cj_product_id', $productId);
        } elseif (! $this->option('all')) {
            $staleBefore = now()->subHours((int) config('lunar-cjdropshipping.sync.stale_after_hours', 6));
            $query->where(fn ($where) => $where->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $staleBefore));
        }

        $count = 0;

        $query->chunkById(200, function (Collection $links) use (&$count): void {
            foreach ($links as $link) {
                SyncProductJob::dispatch($link);
                $count++;
            }
        });

        $this->info("Dispatched {$count} sync job(s).");

        return self::SUCCESS;
    }
}
```

Em `boot()` do provider, adicione `Console\SyncCommand::class` ao array de `$this->commands([...])`.

- [ ] **Step 5: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Feature/Sync`
Expected: `OK (11 tests, ...)`. Depois `vendor/bin/phpunit` → `OK`.

- [ ] **Step 6: Commit**

```bash
git add src tests
git commit -m "feat: sync stock, prices and availability of linked CJ products" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 9: Importar variantes novas

**Files:**
- Create: `src/Actions/ImportNewVariants.php`
- Test: `tests/Feature/Import/ImportNewVariantsTest.php`

**Interfaces:**
- Consumes: `VariantOptionParser` (T4), `OptionResolver`/`VariantWriter` (T7), `Throttle`/`FakeCj` (T5), `ImportsFixtureProduct` (T8).
- Produces: `Actions\ImportNewVariants::handle(ProductLink $link): int` — cria as variantes listadas em `new_cj_variant_ids` (com opções, preço e estoque), limpa a lista e retorna quantas criou; `0` sem chamar a CJ quando a lista está vazia.

- [ ] **Step 1: Escrever o teste que falha**

`tests/Feature/Import/ImportNewVariantsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Import;

use Lunar\Models\ProductVariant;
use Thayron\LunarCjDropshipping\Actions\ImportNewVariants;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\Support\ImportsFixtureProduct;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ImportNewVariantsTest extends TestCase
{
    use CreatesLunarBaseline;
    use ImportsFixtureProduct;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
    }

    public function test_creates_pending_new_variants_and_clears_the_list(): void
    {
        $link = $this->importFixtureProduct();
        $link->forceFill(['new_cj_variant_ids' => ['v-3']])->save();

        $detail = FakeCj::data('product-detail');
        $detail['variants'][] = [
            'vid' => 'v-3', 'pid' => 'p-100', 'variantSku' => 'CJ-CASE-RED-S', 'variantKey' => 'Red-S',
            'variantWeight' => 100, 'variantSellPrice' => '10.00',
        ];
        $stock = FakeCj::data('stock-by-pid');
        $stock['variantInventories'][] = ['vid' => 'v-3', 'inventory' => [['countryCode' => 'CN', 'totalInventory' => 9]]];
        $this->cj->success($detail)->success($stock);

        $created = app(ImportNewVariants::class)->handle($link);

        $this->assertSame(1, $created);
        $red = ProductVariant::query()->where('sku', 'CJ-CASE-RED-S')->sole();
        $this->assertSame($link->lunar_product_id, $red->product_id);
        $this->assertSame(9, (int) $red->stock);
        $this->assertSame(['Red', 'S'], $red->values->sortBy('product_option_id')->map(fn ($value) => $value->name['en'])->values()->all());
        $this->assertSame(1, VariantLink::query()->where('cj_variant_id', 'v-3')->count());
        $this->assertSame([], $link->fresh()->new_cj_variant_ids);
        $this->assertSame(3, $link->product->variants()->count());
    }

    public function test_does_nothing_without_new_variants(): void
    {
        $link = $this->importFixtureProduct();

        $this->assertSame(0, app(ImportNewVariants::class)->handle($link));
        $this->assertCount(2, $this->cj->requests());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Feature/Import/ImportNewVariantsTest.php`
Expected: FAIL — `Class "Thayron\LunarCjDropshipping\Actions\ImportNewVariants" not found`.

- [ ] **Step 3: Implementar**

`src/Actions/ImportNewVariants.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Support\Facades\DB;
use Thayron\CjDropshipping\CjClient;
use Thayron\LunarCjDropshipping\Catalog\OptionResolver;
use Thayron\LunarCjDropshipping\Catalog\VariantWriter;
use Thayron\LunarCjDropshipping\Mapping\VariantOptionParser;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\Throttle;

final class ImportNewVariants
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
        private readonly VariantOptionParser $parser,
        private readonly OptionResolver $options,
        private readonly VariantWriter $variants,
    ) {
    }

    public function handle(ProductLink $link): int
    {
        $pending = $link->new_cj_variant_ids;
        $product = $link->product;

        if ($pending === [] || $product === null) {
            return 0;
        }

        $this->throttle->wait();
        $cjProduct = $this->cj->products()->find($link->cj_product_id);
        $this->throttle->wait();
        $inventory = $this->cj->products()->inventoryByProduct($link->cj_product_id);

        return DB::transaction(function () use ($link, $product, $pending, $cjProduct, $inventory): int {
            $raw = $cjProduct->raw();
            $parsed = $this->parser->parse($raw['productKeyEn'] ?? null, $cjProduct->variants);
            $optionModels = array_map(fn (string $name) => $this->options->option($name), $parsed['options']);
            $attached = $product->productOptions()->get()->modelKeys();

            foreach ($optionModels as $position => $option) {
                if (! in_array($option->id, $attached, true)) {
                    $product->productOptions()->attach($option->id, ['position' => $position + 1]);
                }
            }

            $linked = $link->variantLinks()->pluck('cj_variant_id')->all();
            $created = 0;

            foreach ($cjProduct->variants as $cjVariant) {
                if (! in_array($cjVariant->id, $pending, true) || in_array($cjVariant->id, $linked, true)) {
                    continue;
                }

                $this->variants->create($product, $link, $cjProduct, $cjVariant, $parsed['values'][$cjVariant->id] ?? [], $optionModels, $inventory);
                $created++;
            }

            $link->forceFill(['new_cj_variant_ids' => []])->save();

            return $created;
        });
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Feature/Import/ImportNewVariantsTest.php`
Expected: `OK (2 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add src/Actions/ImportNewVariants.php tests/Feature/Import/ImportNewVariantsTest.php
git commit -m "feat: import variants added on CJ after the first import" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 10: Webhooks (rota, controller, `cj:webhooks:setup`)

**Files:**
- Create: `routes/webhooks.php`, `src/Http/Controllers/WebhookController.php`, `src/Console/WebhooksSetupCommand.php`
- Modify: `src/LunarCjDropshippingServiceProvider.php` (carregar rota, registrar comando)
- Test: `tests/Feature/Webhooks/WebhookControllerTest.php`, `tests/Feature/Webhooks/WebhooksSetupCommandTest.php`

**Interfaces:**
- Consumes: `ProductLink`/`VariantLink` (T2), `SyncProductJob` (T8), `FakeCj` (T5); SDK `Laravel\Middleware\VerifyCjWebhookSignature` (`EVENT_ATTRIBUTE`), `Webhooks\WebhookEvent` (`messageId`, `type: ?WebhookType`, `typeName`, `params`), `Webhooks\WebhookType::{Product, Variant, Stock}`, `Webhooks\SignatureVerifier::sign(string $body, string $openId)`, `Criteria\WebhookSettings::make()->product(string)->stock(string)` (lança `InvalidArgumentException` para URL não-HTTPS), `webhooks()->configure(WebhookSettings): bool`, `webhooks()->subscribeProducts(array): ProductSubscriptionResult` (`successProductIds`, `failedProductIds`).
- Produces: rota `POST {config webhooks.path}` nomeada `lunar-cjdropshipping.webhook`, resposta JSON `{"status": "queued"|"duplicate"|"ignored"}` (200).
- Produces: comando `cj:webhooks:setup`.

- [ ] **Step 1: Escrever os testes que falham**

`tests/Feature/Webhooks/WebhookControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lunar\Models\Product;
use Lunar\Models\ProductVariant;
use Thayron\CjDropshipping\Webhooks\SignatureVerifier;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class WebhookControllerTest extends TestCase
{
    use CreatesLunarBaseline;

    private ProductLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createLunarBaseline();
        FakeCj::install($this->app);

        $product = Product::factory()->create(['product_type_id' => $this->productType->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'tax_class_id' => $this->taxClass->id]);
        $this->link = ProductLink::create(['cj_product_id' => 'p-100', 'lunar_product_id' => $product->id, 'markup_percent' => '100', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []]);
        VariantLink::create(['cj_variant_id' => 'v-1', 'cj_product_link_id' => $this->link->id, 'lunar_variant_id' => $variant->id, 'stock' => 1]);
    }

    public function test_queues_a_sync_for_a_linked_product(): void
    {
        $this->postWebhook(['messageId' => 'm-1', 'type' => 'PRODUCT', 'messageType' => 'UPDATE', 'params' => ['pid' => 'p-100']])
            ->assertOk()
            ->assertExactJson(['status' => 'queued']);

        Queue::assertPushed(SyncProductJob::class, fn (SyncProductJob $job) => $job->link->is($this->link));
    }

    public function test_resolves_the_product_from_a_variant_id(): void
    {
        $this->postWebhook(['messageId' => 'm-2', 'type' => 'STOCK', 'messageType' => 'UPDATE', 'params' => ['vid' => 'v-1']])
            ->assertExactJson(['status' => 'queued']);

        Queue::assertPushed(SyncProductJob::class, 1);
    }

    public function test_ignores_duplicate_messages(): void
    {
        $payload = ['messageId' => 'm-3', 'type' => 'VARIANT', 'messageType' => 'UPDATE', 'params' => ['productId' => 'p-100']];

        $this->postWebhook($payload)->assertExactJson(['status' => 'queued']);
        $this->postWebhook($payload)->assertExactJson(['status' => 'duplicate']);

        Queue::assertPushed(SyncProductJob::class, 1);
    }

    public function test_ignores_unlinked_products_and_other_event_types(): void
    {
        $this->postWebhook(['messageId' => 'm-4', 'type' => 'PRODUCT', 'messageType' => 'UPDATE', 'params' => ['pid' => 'unknown']])
            ->assertExactJson(['status' => 'ignored']);
        $this->postWebhook(['messageId' => 'm-5', 'type' => 'ORDER', 'messageType' => 'UPDATE', 'params' => ['pid' => 'p-100']])
            ->assertExactJson(['status' => 'ignored']);
        $this->postWebhook(['messageId' => 'm-6', 'type' => 'SOMETHING_NEW', 'messageType' => 'INSERT', 'params' => ['pid' => 'p-100']])
            ->assertExactJson(['status' => 'ignored']);

        Queue::assertNothingPushed();
    }

    public function test_rejects_invalid_signatures(): void
    {
        $body = (string) json_encode(['messageId' => 'm-7', 'type' => 'PRODUCT', 'messageType' => 'UPDATE', 'params' => ['pid' => 'p-100']]);

        $this->call('POST', '/cjdropshipping/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGN' => 'invalid'], $body)
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);
        $signature = (new SignatureVerifier)->sign($body, FakeCj::OPEN_ID);

        return $this->call('POST', '/cjdropshipping/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGN' => $signature], $body);
    }
}
```

`tests/Feature/Webhooks/WebhooksSetupCommandTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Webhooks;

use Illuminate\Support\Facades\URL;
use Lunar\Models\Product;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class WebhooksSetupCommandTest extends TestCase
{
    use CreatesLunarBaseline;

    private FakeCj $cj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
    }

    public function test_configures_topics_and_subscribes_linked_products(): void
    {
        URL::forceRootUrl('https://shop.example.com');
        ProductLink::create(['cj_product_id' => 'p-100', 'lunar_product_id' => Product::factory()->create(['product_type_id' => $this->productType->id])->id, 'markup_percent' => '0', 'rounding' => PriceRounding::None, 'new_cj_variant_ids' => []]);
        $this->cj->success(true)->success(['successProductIds' => ['p-100'], 'failProductIds' => [], 'subscribeAll' => false]);

        $this->artisan('cj:webhooks:setup')
            ->expectsOutput('Webhook URL: https://shop.example.com/cjdropshipping/webhook')
            ->expectsOutput('Subscribed 1 product(s), 0 failed.')
            ->assertSuccessful();

        $this->assertSame(['webhook/set', 'webhook/product/subscribe'], $this->cj->paths());
        $settings = $this->cj->jsonAt(0);
        $this->assertSame(['type' => 'ENABLE', 'callbackUrls' => ['https://shop.example.com/cjdropshipping/webhook']], $settings['product']);
        $this->assertSame('ENABLE', $settings['stock']['type']);
        $this->assertSame('CANCEL', $settings['order']['type']);
        $this->assertSame(['productIds' => ['p-100']], $this->cj->jsonAt(1));
    }

    public function test_fails_without_a_public_https_url(): void
    {
        URL::forceRootUrl('http://localhost');

        $this->artisan('cj:webhooks:setup')->assertFailed();

        $this->assertSame([], $this->cj->requests());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Feature/Webhooks`
Expected: FAIL — 404 na rota / comando inexistente.

- [ ] **Step 3: Implementar**

`routes/webhooks.php`:
```php
<?php

use Illuminate\Support\Facades\Route;
use Thayron\CjDropshipping\Laravel\Middleware\VerifyCjWebhookSignature;
use Thayron\LunarCjDropshipping\Http\Controllers\WebhookController;

Route::post((string) config('lunar-cjdropshipping.webhooks.path'), WebhookController::class)
    ->middleware(VerifyCjWebhookSignature::class)
    ->name('lunar-cjdropshipping.webhook');
```

`src/Http/Controllers/WebhookController.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Thayron\CjDropshipping\Laravel\Middleware\VerifyCjWebhookSignature;
use Thayron\CjDropshipping\Webhooks\WebhookEvent;
use Thayron\CjDropshipping\Webhooks\WebhookType;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Models\VariantLink;
use Thayron\LunarCjDropshipping\Support\CjLog;

final class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var WebhookEvent $event */
        $event = $request->attributes->get(VerifyCjWebhookSignature::EVENT_ATTRIBUTE);

        $ttl = now()->addHours((int) config('lunar-cjdropshipping.webhooks.dedupe_ttl_hours', 48));

        if (! Cache::add('lunar-cjdropshipping.webhook.'.sha1($event->messageId), true, $ttl)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        if (! in_array($event->type, [WebhookType::Product, WebhookType::Variant, WebhookType::Stock], true)) {
            CjLog::channel()->debug('Ignored CJ webhook type', ['type' => $event->typeName, 'message_id' => $event->messageId]);

            return new JsonResponse(['status' => 'ignored']);
        }

        $link = $this->findLink($event->params);

        if ($link === null) {
            return new JsonResponse(['status' => 'ignored']);
        }

        SyncProductJob::dispatch($link);

        return new JsonResponse(['status' => 'queued']);
    }

    /**
     * @param  array<array-key, mixed>  $params
     */
    private function findLink(array $params): ?ProductLink
    {
        foreach (['pid', 'productId'] as $key) {
            if (is_scalar($params[$key] ?? null)) {
                $link = ProductLink::query()->where('cj_product_id', (string) $params[$key])->first();

                if ($link !== null) {
                    return $link;
                }
            }
        }

        foreach (['vid', 'variantId'] as $key) {
            if (is_scalar($params[$key] ?? null)) {
                $variantLink = VariantLink::query()->where('cj_variant_id', (string) $params[$key])->first();

                if ($variantLink !== null) {
                    return $variantLink->productLink;
                }
            }
        }

        return null;
    }
}
```

`src/Console/WebhooksSetupCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Criteria\WebhookSettings;
use Thayron\LunarCjDropshipping\Models\ProductLink;

final class WebhooksSetupCommand extends Command
{
    protected $signature = 'cj:webhooks:setup';

    protected $description = 'Point CJdropshipping product and stock webhooks to this app and subscribe linked products';

    public function handle(CjClient $cj): int
    {
        $url = url((string) config('lunar-cjdropshipping.webhooks.path'));

        try {
            $settings = WebhookSettings::make()->product($url)->stock($url);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Webhook URL: {$url}");

        if (! $cj->webhooks()->configure($settings)) {
            $this->error('CJdropshipping did not accept the webhook settings.');

            return self::FAILURE;
        }

        $productIds = ProductLink::query()->pluck('cj_product_id')->all();

        if ($productIds !== []) {
            $result = $cj->webhooks()->subscribeProducts($productIds);
            $this->info(sprintf('Subscribed %d product(s), %d failed.', count($result->successProductIds), count($result->failedProductIds)));
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Registrar no provider**

Em `boot()`: `$this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');` (fora do bloco `runningInConsole`) e adicione `Console\WebhooksSetupCommand::class` ao array de comandos.

- [ ] **Step 5: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Feature/Webhooks`
Expected: `OK (7 tests, ...)`. Depois `vendor/bin/phpunit` → `OK`.

- [ ] **Step 6: Commit**

```bash
git add routes src tests/Feature/Webhooks
git commit -m "feat: handle CJ webhooks and add webhook setup command" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 11: Agendamento

**Files:**
- Modify: `src/LunarCjDropshippingServiceProvider.php`
- Test: `tests/Feature/ScheduleTest.php`

**Interfaces:**
- Consumes: comandos `cj:discover` (T5) e `cj:sync` (T8).
- Produces: com `schedule.enabled = true`, eventos agendados `cj:discover` (frequência `schedule.discover`, padrão `daily`) e `cj:sync` (`schedule.sync`, padrão `everySixHours`), ambos `withoutOverlapping()`. Frequência inválida cai para `daily`.

- [ ] **Step 1: Escrever o teste que falha**

`tests/Feature/ScheduleTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ScheduleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('lunar-cjdropshipping.schedule.enabled', true);
    }

    protected function disableSchedule($app): void
    {
        $app['config']->set('lunar-cjdropshipping.schedule.enabled', false);
    }

    protected function invalidFrequency($app): void
    {
        $app['config']->set('lunar-cjdropshipping.schedule.sync', 'whenever');
    }

    public function test_schedules_discovery_and_sync(): void
    {
        $events = $this->cjEvents();

        $this->assertSame('0 0 * * *', $events['cj:discover']->expression);
        $this->assertSame('0 */6 * * *', $events['cj:sync']->expression);
        $this->assertTrue($events['cj:sync']->withoutOverlapping);
    }

    #[DefineEnvironment('invalidFrequency')]
    public function test_invalid_frequencies_fall_back_to_daily(): void
    {
        $this->assertSame('0 0 * * *', $this->cjEvents()['cj:sync']->expression);
    }

    #[DefineEnvironment('disableSchedule')]
    public function test_does_not_schedule_when_disabled(): void
    {
        $this->assertSame([], $this->cjEvents());
    }

    /**
     * @return array<string, Event>
     */
    private function cjEvents(): array
    {
        $events = [];

        foreach ($this->app->make(Schedule::class)->events() as $event) {
            foreach (['cj:discover', 'cj:sync'] as $command) {
                if (str_contains((string) $event->command, $command)) {
                    $events[$command] = $event;
                }
            }
        }

        return $events;
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Feature/ScheduleTest.php`
Expected: FAIL — `Undefined array key "cj:discover"`.

- [ ] **Step 3: Implementar**

No provider, adicione `use Illuminate\Console\Scheduling\Schedule;` e, em `boot()`:
```php
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('lunar-cjdropshipping.schedule.enabled')) {
                return;
            }

            foreach (['cj:discover' => 'discover', 'cj:sync' => 'sync'] as $command => $key) {
                $event = $schedule->command($command)->withoutOverlapping();
                $frequency = (string) config("lunar-cjdropshipping.schedule.{$key}", 'daily');

                method_exists($event, $frequency) ? $event->{$frequency}() : $event->daily();
            }
        });
```

- [ ] **Step 4: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Feature/ScheduleTest.php`
Expected: `OK (3 tests, ...)`. Se o Testbench resolver `Schedule` antes do `callAfterResolving` (eventos ausentes), troque para `$this->app->booted(fn () => ...)` registrando os eventos no singleton já resolvido e informe no relatório.

- [ ] **Step 5: Commit**

```bash
git add src/LunarCjDropshippingServiceProvider.php tests/Feature/ScheduleTest.php
git commit -m "feat: schedule candidate discovery and product sync" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 12: Admin — plugin Filament, traduções e Regras de importação

**Files:**
- Create: `lang/en/admin.php`, `lang/pt_BR/admin.php`
- Create: `src/Filament/CjDropshippingPlugin.php`, `src/Filament/Support/CjCatalogOptions.php`, `src/Filament/Support/PricePreview.php`
- Create: `src/Filament/Resources/ImportRuleResource.php`, `src/Filament/Resources/ImportRuleResource/Pages/ListImportRules.php`, `CreateImportRule.php`, `EditImportRule.php`
- Modify: `src/LunarCjDropshippingServiceProvider.php` (`loadTranslationsFrom`)
- Create: `tests/FilamentTestCase.php`, `tests/Support/TestPanelProvider.php`
- Test: `tests/Filament/ImportRuleResourceTest.php`

**Interfaces:**
- Consumes: `ImportRule`, `PriceRounding` (T2), `PriceCalculator`/`PricingException` (T3), `DiscoverCandidatesJob` (T5), `CjLog` (T5); Lunar admin `Lunar\Admin\Support\Resources\BaseResource` (hooks `getMainFormComponents(): array`, `getDefaultTable(Table): Table`, `getDefaultPages(): array`, propriedade `$permission`), páginas `Lunar\Admin\Support\Pages\{BaseListRecords, BaseCreateRecord, BaseEditRecord}`, `Lunar\Admin\Support\Facades\LunarPanel`, `Lunar\Admin\Models\Staff` (guard `staff`, Spatie Permission); SDK `products()->categories(): list<Category>` (`id`, `name`, `level`, `parentName`), `products()->warehouses(): list<Warehouse>` (`countryCode`, `countryName`, `disabled`).
- Produces: `Filament\CjDropshippingPlugin` (`getId() = 'lunar-cjdropshipping'`, `make()`), registrando `ImportRuleResource` (T13 adiciona os outros).
- Produces: `Filament\Support\CjCatalogOptions::categories(): array<string,string>` (id → "Nível1 › Nível2 › Nível3", cache `lunar-cjdropshipping.categories` 24h, `[]` em erro) e `countries(): array<string,string>` (código → "Nome (XX)", cache `lunar-cjdropshipping.countries` 24h).
- Produces: `Filament\Support\PricePreview::for(mixed $markupPercent, mixed $rounding, string $costUsd = '10.00'): string` (ex.: `"US$ 10.00 → 18.90 EUR · 15.90 GBP · 20.90 USD"`) e `amounts(string $costUsd, string $markupPercent, PriceRounding $rounding): string` (sem o prefixo).
- Produces (testes): `Tests\FilamentTestCase` com `actingAsStaff(): Staff`.

- [ ] **Step 1: Traduções**

`lang/en/admin.php`:
```php
<?php

return [
    'navigation' => [
        'group' => 'CJdropshipping',
    ],
    'rounding' => [
        'none' => 'No rounding',
        'ends_90' => 'Ends in .90',
        'ends_99' => 'Ends in .99',
        'whole' => 'Whole number',
    ],
    'rules' => [
        'label' => 'Import rule',
        'plural_label' => 'Import rules',
        'fields' => [
            'name' => 'Name',
            'is_active' => 'Active',
            'category_ids' => 'CJ categories',
            'category_ids_help' => 'Third-level CJ categories. Leave empty to search by keyword only.',
            'keyword' => 'Keyword',
            'country_code' => 'Warehouse country',
            'min_stock' => 'Minimum stock',
            'min_cost' => 'Minimum cost',
            'max_cost' => 'Maximum cost',
            'markup_percent' => 'Markup',
            'rounding' => 'Price rounding',
            'price_preview' => 'Price preview',
            'price_preview_help' => 'Sale price for a US$ 10.00 CJ cost in each enabled currency.',
            'product_type_id' => 'Product type',
            'brand_id' => 'Brand',
            'collection_id' => 'Collection',
            'max_pages' => 'Max pages per category',
            'last_run_at' => 'Last run',
            'last_run_stats' => 'Last result',
        ],
        'stats' => ':found found · :created new · :already_imported already imported',
        'validation' => [
            'filter_required' => 'Choose at least one CJ category or a keyword.',
            'max_cost_gte_min' => 'The maximum cost must be greater than or equal to the minimum cost.',
        ],
        'actions' => [
            'discover' => 'Find candidates now',
            'discover_queued' => 'Candidate discovery queued.',
        ],
    ],
    'candidates' => [
        'label' => 'Candidate',
        'plural_label' => 'Candidates',
        'columns' => [
            'image' => 'Image',
            'name' => 'Name',
            'cj_sku' => 'CJ SKU',
            'cost_usd' => 'CJ cost',
            'price' => 'Sale price',
            'warehouse_stock' => 'Stock',
            'rule' => 'Rule',
            'status' => 'Status',
            'discovered_at' => 'Discovered',
        ],
        'status' => [
            'pending' => 'Pending',
            'approved' => 'Approved',
            'ignored' => 'Ignored',
            'importing' => 'Importing',
            'imported' => 'Imported',
            'failed' => 'Failed',
        ],
        'actions' => [
            'import' => 'Import',
            'ignore' => 'Ignore',
            'reset' => 'Back to pending',
            'retry' => 'Try again',
            'open' => 'Open in Lunar',
            'import_queued' => ':count product(s) queued for import.',
        ],
    ],
    'links' => [
        'label' => 'CJ product',
        'plural_label' => 'CJ products',
        'columns' => [
            'product' => 'Product',
            'cj_product_id' => 'CJ product ID',
            'lunar_status' => 'Store status',
            'cj_status' => 'CJ status',
            'variants' => 'Linked variants',
            'new_variants' => 'New on CJ',
            'last_synced_at' => 'Last sync',
            'sync_error' => 'Error',
        ],
        'cj_status' => [
            'active' => 'Active',
            'unavailable' => 'Unavailable',
        ],
        'filters' => [
            'unavailable' => 'Unavailable on CJ',
            'with_error' => 'With sync error',
            'new_variants' => 'With new CJ variants',
        ],
        'actions' => [
            'sync' => 'Sync now',
            'sync_queued' => ':count sync job(s) queued.',
            'import_new_variants' => 'Import new variants',
            'new_variants_imported' => ':count variant(s) imported.',
            'new_variants_failed' => 'Could not import new variants: :message',
        ],
    ],
];
```

`lang/pt_BR/admin.php`: mesma estrutura de chaves, com os textos:
```php
<?php

return [
    'navigation' => [
        'group' => 'CJdropshipping',
    ],
    'rounding' => [
        'none' => 'Sem arredondamento',
        'ends_90' => 'Terminar em ,90',
        'ends_99' => 'Terminar em ,99',
        'whole' => 'Número inteiro',
    ],
    'rules' => [
        'label' => 'Regra de importação',
        'plural_label' => 'Regras de importação',
        'fields' => [
            'name' => 'Nome',
            'is_active' => 'Ativa',
            'category_ids' => 'Categorias CJ',
            'category_ids_help' => 'Categorias de 3º nível da CJ. Deixe vazio para buscar só por palavra-chave.',
            'keyword' => 'Palavra-chave',
            'country_code' => 'País do armazém',
            'min_stock' => 'Estoque mínimo',
            'min_cost' => 'Custo mínimo',
            'max_cost' => 'Custo máximo',
            'markup_percent' => 'Markup',
            'rounding' => 'Arredondamento do preço',
            'price_preview' => 'Prévia de preço',
            'price_preview_help' => 'Preço de venda para um custo CJ de US$ 10,00 em cada moeda habilitada.',
            'product_type_id' => 'Tipo de produto',
            'brand_id' => 'Marca',
            'collection_id' => 'Coleção',
            'max_pages' => 'Máx. de páginas por categoria',
            'last_run_at' => 'Última execução',
            'last_run_stats' => 'Último resultado',
        ],
        'stats' => ':found encontrados · :created novos · :already_imported já importados',
        'validation' => [
            'filter_required' => 'Escolha pelo menos uma categoria CJ ou uma palavra-chave.',
            'max_cost_gte_min' => 'O custo máximo deve ser maior ou igual ao custo mínimo.',
        ],
        'actions' => [
            'discover' => 'Buscar candidatos agora',
            'discover_queued' => 'Busca de candidatos enviada para a fila.',
        ],
    ],
    'candidates' => [
        'label' => 'Candidato',
        'plural_label' => 'Candidatos',
        'columns' => [
            'image' => 'Imagem',
            'name' => 'Nome',
            'cj_sku' => 'SKU CJ',
            'cost_usd' => 'Custo CJ',
            'price' => 'Preço de venda',
            'warehouse_stock' => 'Estoque',
            'rule' => 'Regra',
            'status' => 'Status',
            'discovered_at' => 'Descoberto em',
        ],
        'status' => [
            'pending' => 'Pendente',
            'approved' => 'Aprovado',
            'ignored' => 'Ignorado',
            'importing' => 'Importando',
            'imported' => 'Importado',
            'failed' => 'Falhou',
        ],
        'actions' => [
            'import' => 'Importar',
            'ignore' => 'Ignorar',
            'reset' => 'Voltar para pendente',
            'retry' => 'Tentar de novo',
            'open' => 'Abrir no Lunar',
            'import_queued' => ':count produto(s) enviados para importação.',
        ],
    ],
    'links' => [
        'label' => 'Produto CJ',
        'plural_label' => 'Produtos CJ',
        'columns' => [
            'product' => 'Produto',
            'cj_product_id' => 'ID do produto CJ',
            'lunar_status' => 'Status na loja',
            'cj_status' => 'Status na CJ',
            'variants' => 'Variantes vinculadas',
            'new_variants' => 'Novas na CJ',
            'last_synced_at' => 'Última sincronização',
            'sync_error' => 'Erro',
        ],
        'cj_status' => [
            'active' => 'Ativo',
            'unavailable' => 'Indisponível',
        ],
        'filters' => [
            'unavailable' => 'Indisponível na CJ',
            'with_error' => 'Com erro de sincronização',
            'new_variants' => 'Com variantes novas na CJ',
        ],
        'actions' => [
            'sync' => 'Sincronizar agora',
            'sync_queued' => ':count sincronização(ões) enviadas para a fila.',
            'import_new_variants' => 'Importar variantes novas',
            'new_variants_imported' => ':count variante(s) importada(s).',
            'new_variants_failed' => 'Não foi possível importar as variantes novas: :message',
        ],
    ],
];
```

No provider, em `boot()`: `$this->loadTranslationsFrom(__DIR__.'/../lang', 'lunar-cjdropshipping');`

- [ ] **Step 2: Harness Filament**

`tests/Support/TestPanelProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Support;

use Filament\Panel;
use Illuminate\Support\ServiceProvider;
use Lunar\Admin\Support\Facades\LunarPanel;
use Thayron\LunarCjDropshipping\Filament\CjDropshippingPlugin;

final class TestPanelProvider extends ServiceProvider
{
    public function register(): void
    {
        LunarPanel::disableTwoFactorAuth();
        LunarPanel::panel(fn (Panel $panel): Panel => $panel->plugins([CjDropshippingPlugin::make()]))->register();
    }
}
```

`tests/FilamentTestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests;

use Filament\Facades\Filament;
use Lunar\Admin\Models\Staff;
use Spatie\Permission\Models\Permission;
use Thayron\LunarCjDropshipping\Tests\Support\TestPanelProvider;

abstract class FilamentTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        $filament = array_values(array_filter([
            \Livewire\LivewireServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \Technikermathe\LucideIcons\BladeLucideIconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \Kirschbaum\PowerJoins\PowerJoinsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Awcodes\FilamentBadgeableColumn\BadgeableColumnServiceProvider::class,
            \Awcodes\Shout\ShoutServiceProvider::class,
            \Leandrocfe\FilamentApexCharts\FilamentApexChartsServiceProvider::class,
            \Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticationServiceProvider::class,
            \Spatie\LaravelPasskeys\LaravelPasskeysServiceProvider::class,
            \Barryvdh\DomPDF\ServiceProvider::class,
            \Spatie\Permission\PermissionServiceProvider::class,
        ], fn (string $provider): bool => class_exists($provider)));

        return [
            ...$filament,
            ...parent::getPackageProviders($app),
            \Lunar\Admin\LunarPanelProvider::class,
            TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function actingAsStaff(): Staff
    {
        $staff = Staff::factory()->create(['admin' => true]);
        Permission::findOrCreate('catalog:manage-products', 'staff');
        $staff->givePermissionTo('catalog:manage-products');

        $this->actingAs($staff, 'staff');
        Filament::setCurrentPanel(Filament::getPanel('lunar'));

        return $staff;
    }
}
```

- [ ] **Step 3: Escrever o teste que falha**

`tests/Filament/ImportRuleResourceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource\Pages\CreateImportRule;
use Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource\Pages\ListImportRules;
use Thayron\LunarCjDropshipping\Filament\Support\PricePreview;
use Thayron\LunarCjDropshipping\Jobs\DiscoverCandidatesJob;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Tests\FilamentTestCase;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;

final class ImportRuleResourceTest extends FilamentTestCase
{
    use CreatesLunarBaseline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        Cache::put('lunar-cjdropshipping.categories', ['cat-1' => 'Phones › Accessories › Cases'], 3600);
        Cache::put('lunar-cjdropshipping.countries', ['US' => 'United States (US)'], 3600);
        $this->actingAsStaff();
    }

    public function test_lists_rules(): void
    {
        $rule = $this->rule();

        Livewire::test(ListImportRules::class)->assertCanSeeTableRecords([$rule]);
    }

    public function test_creates_a_rule(): void
    {
        Livewire::test(CreateImportRule::class)
            ->fillForm([
                'name' => 'Phone cases',
                'is_active' => true,
                'category_ids' => ['cat-1'],
                'country_code' => 'US',
                'min_stock' => 10,
                'markup_percent' => 120,
                'rounding' => 'ends_90',
                'product_type_id' => $this->productType->id,
                'max_pages' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = ImportRule::query()->sole();
        $this->assertSame(['cat-1'], $rule->category_ids);
        $this->assertSame(PriceRounding::Ends90, $rule->rounding);
        $this->assertSame('120.00', $rule->markup_percent);
    }

    public function test_requires_a_category_or_keyword_and_valid_limits(): void
    {
        Livewire::test(CreateImportRule::class)
            ->fillForm([
                'name' => 'Invalid',
                'category_ids' => [],
                'keyword' => '',
                'markup_percent' => 50,
                'rounding' => 'none',
                'product_type_id' => $this->productType->id,
                'min_cost' => 10,
                'max_cost' => 5,
                'max_pages' => 1001,
            ])
            ->call('create')
            ->assertHasFormErrors(['keyword', 'max_cost', 'max_pages']);

        $this->assertSame(0, ImportRule::query()->count());
    }

    public function test_discover_action_queues_the_job(): void
    {
        Queue::fake();
        $rule = $this->rule();

        Livewire::test(ListImportRules::class)->callTableAction('discover', $rule);

        Queue::assertPushed(DiscoverCandidatesJob::class, fn (DiscoverCandidatesJob $job) => $job->rule->is($rule));
    }

    public function test_price_preview_shows_every_enabled_currency(): void
    {
        $this->assertSame('US$ 10.00 → 18.90 EUR · 15.90 GBP · 20.90 USD', PricePreview::for('100', 'ends_90'));
        $this->assertSame('US$ 10.00 → 9.26 EUR · 7.87 GBP · 10.00 USD', PricePreview::for(null, null));
    }

    private function rule(): ImportRule
    {
        return ImportRule::create(['name' => 'Rule', 'keyword' => 'case', 'markup_percent' => '100', 'rounding' => PriceRounding::None, 'product_type_id' => $this->productType->id]);
    }
}
```

Nota: sem markup/arredondamento: 10 USD / 1,08 = 9,259 → 9,26 EUR; × 0,85 = 7,870 → 7,87 GBP; USD 9,99999999998 → 10,00.

- [ ] **Step 4: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Filament/ImportRuleResourceTest.php`
Expected: FAIL — classes do plugin inexistentes. Se o boot do painel Lunar falhar por provider ausente/ordem de providers, ajuste a lista em `FilamentTestCase::getPackageProviders()` (adicione o provider que o erro indicar) e registre no relatório.

- [ ] **Step 5: Implementar suporte**

`src/Filament/Support/CjCatalogOptions.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Support;

use Illuminate\Support\Facades\Cache;
use Thayron\CjDropshipping\CjClient;
use Thayron\LunarCjDropshipping\Support\CjLog;
use Throwable;

final class CjCatalogOptions
{
    private const TTL = 86400;

    /**
     * @return array<string, string> third-level category id => "Level 1 › Level 2 › Level 3"
     */
    public static function categories(): array
    {
        return self::remember('lunar-cjdropshipping.categories', function (): array {
            $options = [];
            $first = null;

            foreach (app(CjClient::class)->products()->categories() as $category) {
                if ($category->level === 1) {
                    $first = $category->name;
                }

                if ($category->level === 3 && $category->id !== null) {
                    $options[$category->id] = implode(' › ', array_filter([$first, $category->parentName, $category->name]));
                }
            }

            asort($options);

            return $options;
        });
    }

    /**
     * @return array<string, string> country code => "Country (XX)"
     */
    public static function countries(): array
    {
        return self::remember('lunar-cjdropshipping.countries', function (): array {
            $options = [];

            foreach (app(CjClient::class)->products()->warehouses() as $warehouse) {
                if ($warehouse->disabled || $warehouse->countryCode === null) {
                    continue;
                }

                $options[$warehouse->countryCode] = sprintf('%s (%s)', $warehouse->countryName ?? $warehouse->countryCode, $warehouse->countryCode);
            }

            asort($options);

            return $options;
        });
    }

    /**
     * @param  callable(): array<string, string>  $resolver
     * @return array<string, string>
     */
    private static function remember(string $key, callable $resolver): array
    {
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $options = $resolver();
            Cache::put($key, $options, self::TTL);

            return $options;
        } catch (Throwable $exception) {
            CjLog::channel()->warning('Could not load CJ options', ['key' => $key, 'message' => $exception->getMessage()]);

            return [];
        }
    }
}
```

`src/Filament/Support/PricePreview.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Support;

use Lunar\Models\Currency;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Exceptions\PricingException;
use Thayron\LunarCjDropshipping\Pricing\PriceCalculator;

final class PricePreview
{
    public static function for(mixed $markupPercent, mixed $rounding, string $costUsd = '10.00'): string
    {
        $markup = is_numeric($markupPercent) ? (string) $markupPercent : '0';
        $roundingCase = $rounding instanceof PriceRounding ? $rounding : (PriceRounding::tryFrom((string) $rounding) ?? PriceRounding::None);

        return 'US$ '.$costUsd.' → '.self::amounts($costUsd, $markup, $roundingCase);
    }

    public static function amounts(string $costUsd, string $markupPercent, PriceRounding $rounding): string
    {
        try {
            $prices = app(PriceCalculator::class)->pricesFor($costUsd, $markupPercent, $rounding);
        } catch (PricingException $exception) {
            return $exception->getMessage();
        }

        $currencies = Currency::query()->whereKey(array_keys($prices))->get()->keyBy('id');

        return collect($prices)
            ->map(function (int $amount, int $currencyId) use ($currencies): string {
                $currency = $currencies[$currencyId];
                $decimals = (int) $currency->decimal_places;

                return number_format($amount / (10 ** $decimals), $decimals, '.', '').' '.$currency->code;
            })
            ->implode(' · ');
    }
}
```

- [ ] **Step 6: Implementar plugin, resource e páginas**

`src/Filament/CjDropshippingPlugin.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource;

final class CjDropshippingPlugin implements Plugin
{
    public function getId(): string
    {
        return 'lunar-cjdropshipping';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            ImportRuleResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
    }

    public static function make(): self
    {
        return app(self::class);
    }
}
```

`src/Filament/Resources/ImportRuleResource.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources;

use Closure;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Lunar\Admin\Support\Resources\BaseResource;
use Lunar\Models\Collection;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource\Pages;
use Thayron\LunarCjDropshipping\Filament\Support\CjCatalogOptions;
use Thayron\LunarCjDropshipping\Filament\Support\PricePreview;
use Thayron\LunarCjDropshipping\Jobs\DiscoverCandidatesJob;
use Thayron\LunarCjDropshipping\Models\ImportRule;

class ImportRuleResource extends BaseResource
{
    protected static ?string $permission = 'catalog:manage-products';

    protected static ?string $model = ImportRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?int $navigationSort = 1;

    public static function getLabel(): string
    {
        return __('lunar-cjdropshipping::admin.rules.label');
    }

    public static function getPluralLabel(): string
    {
        return __('lunar-cjdropshipping::admin.rules.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lunar-cjdropshipping::admin.navigation.group');
    }

    /**
     * @return array<string, string>
     */
    public static function roundingOptions(): array
    {
        return collect(PriceRounding::cases())
            ->mapWithKeys(fn (PriceRounding $rounding) => [$rounding->value => __('lunar-cjdropshipping::admin.rounding.'.$rounding->value)])
            ->all();
    }

    protected static function getMainFormComponents(): array
    {
        $field = fn (string $key): string => __("lunar-cjdropshipping::admin.rules.fields.{$key}");

        return [
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label($field('name'))->required()->maxLength(255),
                Forms\Components\Toggle::make('is_active')->label($field('is_active'))->default(true)->inline(false),
                Forms\Components\Select::make('category_ids')
                    ->label($field('category_ids'))
                    ->helperText($field('category_ids_help'))
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => CjCatalogOptions::categories())
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('keyword')
                    ->label($field('keyword'))
                    ->maxLength(255)
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (blank($value) && blank($get('category_ids'))) {
                                $fail(__('lunar-cjdropshipping::admin.rules.validation.filter_required'));
                            }
                        },
                    ]),
                Forms\Components\Select::make('country_code')
                    ->label($field('country_code'))
                    ->searchable()
                    ->options(fn (): array => CjCatalogOptions::countries()),
                Forms\Components\TextInput::make('min_stock')->label($field('min_stock'))->numeric()->minValue(0)->default(0)->required(),
                Forms\Components\TextInput::make('max_pages')->label($field('max_pages'))->numeric()->minValue(1)->maxValue(1000)->default(5)->required(),
                Forms\Components\TextInput::make('min_cost')->label($field('min_cost'))->numeric()->minValue(0)->prefix('US$'),
                Forms\Components\TextInput::make('max_cost')
                    ->label($field('max_cost'))
                    ->numeric()
                    ->minValue(0)
                    ->prefix('US$')
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (filled($value) && filled($get('min_cost')) && (float) $value < (float) $get('min_cost')) {
                                $fail(__('lunar-cjdropshipping::admin.rules.validation.max_cost_gte_min'));
                            }
                        },
                    ]),
                Forms\Components\TextInput::make('markup_percent')->label($field('markup_percent'))->numeric()->minValue(0)->suffix('%')->default(100)->required()->live(debounce: 500),
                Forms\Components\Select::make('rounding')->label($field('rounding'))->options(static::roundingOptions())->default(PriceRounding::None->value)->required()->live(),
                Forms\Components\Placeholder::make('price_preview')
                    ->label($field('price_preview'))
                    ->helperText($field('price_preview_help'))
                    ->content(fn (Get $get): string => PricePreview::for($get('markup_percent'), $get('rounding')))
                    ->columnSpanFull(),
                Forms\Components\Select::make('product_type_id')->label($field('product_type_id'))->relationship('productType', 'name')->preload()->required(),
                Forms\Components\Select::make('brand_id')->label($field('brand_id'))->relationship('brand', 'name')->searchable()->preload(),
                Forms\Components\Select::make('collection_id')
                    ->label($field('collection_id'))
                    ->searchable()
                    ->options(fn (): array => Collection::query()->get()->mapWithKeys(fn (Collection $collection) => [$collection->id => (string) ($collection->translateAttribute('name') ?? "#{$collection->id}")])->all()),
            ]),
        ];
    }

    protected static function getDefaultTable(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('lunar-cjdropshipping::admin.rules.fields.name'))->searchable()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label(__('lunar-cjdropshipping::admin.rules.fields.is_active'))->boolean(),
                Tables\Columns\TextColumn::make('markup_percent')->label(__('lunar-cjdropshipping::admin.rules.fields.markup_percent'))->suffix('%'),
                Tables\Columns\TextColumn::make('last_run_at')->label(__('lunar-cjdropshipping::admin.rules.fields.last_run_at'))->dateTime()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('last_run_summary')
                    ->label(__('lunar-cjdropshipping::admin.rules.fields.last_run_stats'))
                    ->state(fn (ImportRule $record): ?string => static::statsSummary($record))
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('discover')
                    ->label(__('lunar-cjdropshipping::admin.rules.actions.discover'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->action(function (ImportRule $record): void {
                        DiscoverCandidatesJob::dispatch($record);
                        Notification::make()->title(__('lunar-cjdropshipping::admin.rules.actions.discover_queued'))->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function statsSummary(ImportRule $rule): ?string
    {
        $stats = $rule->last_run_stats;

        if (! is_array($stats)) {
            return null;
        }

        if (isset($stats['error'])) {
            return (string) $stats['error'];
        }

        return __('lunar-cjdropshipping::admin.rules.stats', [
            'found' => $stats['found'] ?? 0,
            'created' => $stats['created'] ?? 0,
            'already_imported' => $stats['already_imported'] ?? 0,
        ]);
    }

    public static function getDefaultPages(): array
    {
        return [
            'index' => Pages\ListImportRules::route('/'),
            'create' => Pages\CreateImportRule::route('/create'),
            'edit' => Pages\EditImportRule::route('/{record}/edit'),
        ];
    }
}
```

`src/Filament/Resources/ImportRuleResource/Pages/ListImportRules.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource\Pages;

use Filament\Actions;
use Lunar\Admin\Support\Pages\BaseListRecords;
use Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource;

class ListImportRules extends BaseListRecords
{
    protected static string $resource = ImportRuleResource::class;

    protected function getDefaultHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
```

`src/Filament/Resources/ImportRuleResource/Pages/CreateImportRule.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource\Pages;

use Lunar\Admin\Support\Pages\BaseCreateRecord;
use Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource;

class CreateImportRule extends BaseCreateRecord
{
    protected static string $resource = ImportRuleResource::class;
}
```

`src/Filament/Resources/ImportRuleResource/Pages/EditImportRule.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource\Pages;

use Filament\Actions;
use Lunar\Admin\Support\Pages\BaseEditRecord;
use Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource;

class EditImportRule extends BaseEditRecord
{
    protected static string $resource = ImportRuleResource::class;

    protected function getDefaultHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
```

Nota: se `BaseListRecords`/`BaseEditRecord` do Lunar 1.4 não expuserem `getDefaultHeaderActions()` (confira em `vendor/lunarphp/lunar/src/Support/Pages/Concerns`), use `getHeaderActions()` e registre no relatório.

- [ ] **Step 7: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Filament/ImportRuleResourceTest.php`
Expected: `OK (5 tests, ...)`. Depois `vendor/bin/phpunit` → `OK`.

- [ ] **Step 8: Commit**

```bash
git add lang src tests
git commit -m "feat: add Filament plugin and import rules admin" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 13: Admin — Candidatos e Produtos CJ

**Files:**
- Create: `src/Filament/Resources/CandidateResource.php`, `src/Filament/Resources/CandidateResource/Pages/ListCandidates.php`
- Create: `src/Filament/Resources/ProductLinkResource.php`, `src/Filament/Resources/ProductLinkResource/Pages/ListProductLinks.php`
- Modify: `src/Filament/CjDropshippingPlugin.php` (registrar os 2 resources)
- Test: `tests/Filament/CandidateResourceTest.php`, `tests/Filament/ProductLinkResourceTest.php`

**Interfaces:**
- Consumes: models/enums (T2), `ImportProductJob` (T7), `SyncProductJob` (T8), `ImportNewVariants` (T9), `PricePreview::amounts()` (T12), `FilamentTestCase`/`FakeCj`/`ImportsFixtureProduct` (T5, T8, T12); Lunar `Lunar\Admin\Filament\Resources\ProductResource::getUrl('edit', ['record' => id])`.
- Produces: `CandidateResource` (somente listagem; `canCreate()` false) com ações de linha `import`, `ignore`, `retry`, `open` e bulk `import`, `ignore`, `reset`; filtro `status` (padrão `pending`) e `import_rule_id`.
- Produces: `CandidateResource::approve(iterable<Candidate> $candidates): int` — marca `pending`/`failed`/`ignored` como `approved` e despacha `ImportProductJob`; ignora `importing`/`imported`/`approved`.
- Produces: `ProductLinkResource` (somente listagem) com ações `sync` (linha e bulk) e `import_new_variants` (linha, visível quando há variantes novas); filtros `unavailable`, `with_error`, `new_variants`.

- [ ] **Step 1: Escrever os testes que falham**

`tests/Filament/CandidateResourceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Enums\PriceRounding;
use Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource\Pages\ListCandidates;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Tests\FilamentTestCase;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;

final class CandidateResourceTest extends FilamentTestCase
{
    use CreatesLunarBaseline;

    private ImportRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createLunarBaseline();
        $this->actingAsStaff();
        $this->rule = ImportRule::create(['name' => 'Rule', 'keyword' => 'case', 'markup_percent' => '100', 'rounding' => PriceRounding::Ends90, 'product_type_id' => $this->productType->id]);
    }

    public function test_shows_pending_candidates_by_default_with_calculated_prices(): void
    {
        $pending = $this->candidate('p-1', CandidateStatus::Pending);
        $ignored = $this->candidate('p-2', CandidateStatus::Ignored);

        Livewire::test(ListCandidates::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$ignored])
            ->assertSee('18.90 EUR');
    }

    public function test_bulk_import_approves_and_queues_candidates(): void
    {
        $pending = $this->candidate('p-1', CandidateStatus::Pending);
        $failed = $this->candidate('p-2', CandidateStatus::Failed);
        $imported = $this->candidate('p-3', CandidateStatus::Imported);

        Livewire::test(ListCandidates::class)
            ->filterTable('status', null)
            ->callTableBulkAction('import', [$pending, $failed, $imported]);

        $this->assertSame(CandidateStatus::Approved, $pending->fresh()->status);
        $this->assertSame(CandidateStatus::Approved, $failed->fresh()->status);
        $this->assertSame(CandidateStatus::Imported, $imported->fresh()->status);
        Queue::assertPushed(ImportProductJob::class, 2);
    }

    public function test_bulk_ignore_and_reset(): void
    {
        $candidate = $this->candidate('p-1', CandidateStatus::Pending);

        Livewire::test(ListCandidates::class)->callTableBulkAction('ignore', [$candidate]);
        $this->assertSame(CandidateStatus::Ignored, $candidate->fresh()->status);

        Livewire::test(ListCandidates::class)->filterTable('status', 'ignored')->callTableBulkAction('reset', [$candidate]);
        $this->assertSame(CandidateStatus::Pending, $candidate->fresh()->status);
    }

    public function test_retry_action_queues_failed_candidates(): void
    {
        $candidate = $this->candidate('p-1', CandidateStatus::Failed);

        Livewire::test(ListCandidates::class)
            ->filterTable('status', 'failed')
            ->callTableAction('retry', $candidate);

        Queue::assertPushed(ImportProductJob::class, fn (ImportProductJob $job) => $job->candidate->is($candidate));
    }

    private function candidate(string $cjProductId, CandidateStatus $status): Candidate
    {
        return Candidate::create([
            'import_rule_id' => $this->rule->id,
            'cj_product_id' => $cjProductId,
            'cj_sku' => 'SKU-'.$cjProductId,
            'name' => 'Product '.$cjProductId,
            'cost_usd' => '10.00',
            'warehouse_stock' => 10,
            'status' => $status,
            'payload' => [],
            'discovered_at' => now(),
        ]);
    }
}
```

`tests/Filament/ProductLinkResourceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Filament;

use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Lunar\Models\ProductVariant;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Filament\Resources\ProductLinkResource\Pages\ListProductLinks;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\FilamentTestCase;
use Thayron\LunarCjDropshipping\Tests\Support\CreatesLunarBaseline;
use Thayron\LunarCjDropshipping\Tests\Support\FakeCj;
use Thayron\LunarCjDropshipping\Tests\Support\ImportsFixtureProduct;

final class ProductLinkResourceTest extends FilamentTestCase
{
    use CreatesLunarBaseline;
    use ImportsFixtureProduct;

    private FakeCj $cj;

    private ProductLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLunarBaseline();
        $this->cj = FakeCj::install($this->app);
        $this->link = $this->importFixtureProduct();
        $this->actingAsStaff();
    }

    public function test_lists_linked_products_and_filters_unavailable_ones(): void
    {
        Livewire::test(ListProductLinks::class)
            ->assertCanSeeTableRecords([$this->link])
            ->assertSee('Magnetic Phone Case');

        Livewire::test(ListProductLinks::class)
            ->filterTable('unavailable')
            ->assertCanNotSeeTableRecords([$this->link]);

        $this->link->forceFill(['cj_status' => CjProductStatus::Unavailable])->save();

        Livewire::test(ListProductLinks::class)
            ->filterTable('unavailable')
            ->assertCanSeeTableRecords([$this->link]);
    }

    public function test_sync_actions_queue_jobs(): void
    {
        Queue::fake();

        Livewire::test(ListProductLinks::class)->callTableAction('sync', $this->link);
        Livewire::test(ListProductLinks::class)->callTableBulkAction('sync', [$this->link]);

        Queue::assertPushed(SyncProductJob::class, 2);
    }

    public function test_imports_new_variants(): void
    {
        $this->link->forceFill(['new_cj_variant_ids' => ['v-3']])->save();
        $detail = FakeCj::data('product-detail');
        $detail['variants'][] = ['vid' => 'v-3', 'pid' => 'p-100', 'variantSku' => 'CJ-CASE-RED-S', 'variantKey' => 'Red-S', 'variantSellPrice' => '10.00'];
        $this->cj->success($detail)->fixture('stock-by-pid');

        Livewire::test(ListProductLinks::class)->callTableAction('import_new_variants', $this->link);

        $this->assertSame(1, ProductVariant::query()->where('sku', 'CJ-CASE-RED-S')->count());
        $this->assertSame([], $this->link->fresh()->new_cj_variant_ids);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/phpunit tests/Filament/CandidateResourceTest.php tests/Filament/ProductLinkResourceTest.php`
Expected: FAIL — classes inexistentes.

- [ ] **Step 3: Implementar CandidateResource**

`src/Filament/Resources/CandidateResource.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources;

use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Lunar\Admin\Filament\Resources\ProductResource;
use Lunar\Admin\Support\Resources\BaseResource;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource\Pages;
use Thayron\LunarCjDropshipping\Filament\Support\PricePreview;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Models\Candidate;

class CandidateResource extends BaseResource
{
    protected static ?string $permission = 'catalog:manage-products';

    protected static ?string $model = Candidate::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = 2;

    public static function getLabel(): string
    {
        return __('lunar-cjdropshipping::admin.candidates.label');
    }

    public static function getPluralLabel(): string
    {
        return __('lunar-cjdropshipping::admin.candidates.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lunar-cjdropshipping::admin.navigation.group');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('importRule');
    }

    /**
     * @param  iterable<Candidate>  $candidates
     */
    public static function approve(iterable $candidates): int
    {
        $count = 0;

        foreach ($candidates as $candidate) {
            if (! in_array($candidate->status, [CandidateStatus::Pending, CandidateStatus::Failed, CandidateStatus::Ignored], true)) {
                continue;
            }

            $candidate->forceFill(['status' => CandidateStatus::Approved, 'error' => null])->save();
            ImportProductJob::dispatch($candidate);
            $count++;
        }

        Notification::make()->title(__('lunar-cjdropshipping::admin.candidates.actions.import_queued', ['count' => $count]))->success()->send();

        return $count;
    }

    protected static function getDefaultTable(Table $table): Table
    {
        $column = fn (string $key): string => __("lunar-cjdropshipping::admin.candidates.columns.{$key}");

        return $table
            ->defaultSort('discovered_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('image_url')->label($column('image'))->square()->size(56),
                Tables\Columns\TextColumn::make('name')->label($column('name'))->searchable()->wrap()->limit(80),
                Tables\Columns\TextColumn::make('cj_sku')->label($column('cj_sku'))->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('cost_usd')->label($column('cost_usd'))->prefix('US$ ')->sortable(),
                Tables\Columns\TextColumn::make('sale_price')
                    ->label($column('price'))
                    ->state(fn (Candidate $record): ?string => $record->cost_usd === null
                        ? null
                        : PricePreview::amounts((string) $record->cost_usd, (string) $record->importRule->markup_percent, $record->importRule->rounding))
                    ->wrap(),
                Tables\Columns\TextColumn::make('warehouse_stock')->label($column('warehouse_stock'))->numeric()->sortable(),
                Tables\Columns\TextColumn::make('importRule.name')->label($column('rule'))->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label($column('status'))
                    ->badge()
                    ->formatStateUsing(fn (CandidateStatus $state): string => __('lunar-cjdropshipping::admin.candidates.status.'.$state->value))
                    ->color(fn (CandidateStatus $state): string => match ($state) {
                        CandidateStatus::Pending => 'gray',
                        CandidateStatus::Approved, CandidateStatus::Importing => 'info',
                        CandidateStatus::Imported => 'success',
                        CandidateStatus::Ignored => 'warning',
                        CandidateStatus::Failed => 'danger',
                    })
                    ->tooltip(fn (Candidate $record): ?string => $record->error),
                Tables\Columns\TextColumn::make('discovered_at')->label($column('discovered_at'))->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(CandidateStatus::cases())->mapWithKeys(fn (CandidateStatus $status) => [$status->value => __('lunar-cjdropshipping::admin.candidates.status.'.$status->value)])->all())
                    ->default(CandidateStatus::Pending->value),
                Tables\Filters\SelectFilter::make('import_rule_id')->label($column('rule'))->relationship('importRule', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('import')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.import'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Candidate $record): bool => $record->status === CandidateStatus::Pending)
                    ->action(fn (Candidate $record) => static::approve([$record])),
                Tables\Actions\Action::make('retry')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.retry'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Candidate $record): bool => $record->status === CandidateStatus::Failed)
                    ->action(fn (Candidate $record) => static::approve([$record])),
                Tables\Actions\Action::make('ignore')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.ignore'))
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (Candidate $record): bool => in_array($record->status, [CandidateStatus::Pending, CandidateStatus::Failed], true))
                    ->action(fn (Candidate $record) => $record->forceFill(['status' => CandidateStatus::Ignored])->save()),
                Tables\Actions\Action::make('open')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (Candidate $record): bool => $record->lunar_product_id !== null)
                    ->url(fn (Candidate $record): string => ProductResource::getUrl('edit', ['record' => $record->lunar_product_id])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('import')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.import'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (EloquentCollection $records) => static::approve($records)),
                Tables\Actions\BulkAction::make('ignore')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.ignore'))
                    ->icon('heroicon-o-eye-slash')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (EloquentCollection $records) => $records->each(fn (Candidate $candidate) => $candidate->forceFill(['status' => CandidateStatus::Ignored])->save())),
                Tables\Actions\BulkAction::make('reset')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.reset'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (EloquentCollection $records) => $records
                        ->filter(fn (Candidate $candidate) => in_array($candidate->status, [CandidateStatus::Ignored, CandidateStatus::Failed], true))
                        ->each(fn (Candidate $candidate) => $candidate->forceFill(['status' => CandidateStatus::Pending, 'error' => null])->save())),
            ]);
    }

    public static function getDefaultPages(): array
    {
        return [
            'index' => Pages\ListCandidates::route('/'),
        ];
    }
}
```

`src/Filament/Resources/CandidateResource/Pages/ListCandidates.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource\Pages;

use Lunar\Admin\Support\Pages\BaseListRecords;
use Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource;

class ListCandidates extends BaseListRecords
{
    protected static string $resource = CandidateResource::class;
}
```

- [ ] **Step 4: Implementar ProductLinkResource**

`src/Filament/Resources/ProductLinkResource.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources;

use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Lunar\Admin\Filament\Resources\ProductResource;
use Lunar\Admin\Support\Resources\BaseResource;
use Thayron\LunarCjDropshipping\Actions\ImportNewVariants;
use Thayron\LunarCjDropshipping\Enums\CjProductStatus;
use Thayron\LunarCjDropshipping\Filament\Resources\ProductLinkResource\Pages;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Throwable;

class ProductLinkResource extends BaseResource
{
    protected static ?string $permission = 'catalog:manage-products';

    protected static ?string $model = ProductLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 3;

    public static function getLabel(): string
    {
        return __('lunar-cjdropshipping::admin.links.label');
    }

    public static function getPluralLabel(): string
    {
        return __('lunar-cjdropshipping::admin.links.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lunar-cjdropshipping::admin.navigation.group');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('product')->withCount('variantLinks');
    }

    /**
     * @param  iterable<ProductLink>  $links
     */
    public static function queueSync(iterable $links): void
    {
        $count = 0;

        foreach ($links as $link) {
            SyncProductJob::dispatch($link);
            $count++;
        }

        Notification::make()->title(__('lunar-cjdropshipping::admin.links.actions.sync_queued', ['count' => $count]))->success()->send();
    }

    protected static function getDefaultTable(Table $table): Table
    {
        $column = fn (string $key): string => __("lunar-cjdropshipping::admin.links.columns.{$key}");

        return $table
            ->defaultSort('last_synced_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label($column('product'))
                    ->state(fn (ProductLink $record): ?string => $record->product?->translateAttribute('name'))
                    ->url(fn (ProductLink $record): string => ProductResource::getUrl('edit', ['record' => $record->lunar_product_id]))
                    ->wrap(),
                Tables\Columns\TextColumn::make('cj_product_id')->label($column('cj_product_id'))->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('product.status')->label($column('lunar_status'))->badge(),
                Tables\Columns\TextColumn::make('cj_status')
                    ->label($column('cj_status'))
                    ->badge()
                    ->formatStateUsing(fn (CjProductStatus $state): string => __('lunar-cjdropshipping::admin.links.cj_status.'.$state->value))
                    ->color(fn (CjProductStatus $state): string => $state === CjProductStatus::Active ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('variant_links_count')->label($column('variants'))->numeric(),
                Tables\Columns\TextColumn::make('new_variants')
                    ->label($column('new_variants'))
                    ->state(fn (ProductLink $record): int => count($record->new_cj_variant_ids))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('last_synced_at')->label($column('last_synced_at'))->since()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('sync_error')->label($column('sync_error'))->limit(40)->tooltip(fn (ProductLink $record): ?string => $record->sync_error)->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\Filter::make('unavailable')
                    ->label(__('lunar-cjdropshipping::admin.links.filters.unavailable'))
                    ->query(fn (Builder $query): Builder => $query->where('cj_status', CjProductStatus::Unavailable->value)),
                Tables\Filters\Filter::make('with_error')
                    ->label(__('lunar-cjdropshipping::admin.links.filters.with_error'))
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('sync_error')),
                Tables\Filters\Filter::make('new_variants')
                    ->label(__('lunar-cjdropshipping::admin.links.filters.new_variants'))
                    ->query(fn (Builder $query): Builder => $query->where('new_cj_variant_ids', '!=', '[]')),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label(__('lunar-cjdropshipping::admin.links.actions.sync'))
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn (ProductLink $record) => static::queueSync([$record])),
                Tables\Actions\Action::make('import_new_variants')
                    ->label(__('lunar-cjdropshipping::admin.links.actions.import_new_variants'))
                    ->icon('heroicon-o-plus-circle')
                    ->visible(fn (ProductLink $record): bool => $record->new_cj_variant_ids !== [])
                    ->requiresConfirmation()
                    ->action(function (ProductLink $record): void {
                        try {
                            $count = app(ImportNewVariants::class)->handle($record);
                            Notification::make()->title(__('lunar-cjdropshipping::admin.links.actions.new_variants_imported', ['count' => $count]))->success()->send();
                        } catch (Throwable $exception) {
                            Notification::make()->title(__('lunar-cjdropshipping::admin.links.actions.new_variants_failed', ['message' => $exception->getMessage()]))->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('sync')
                    ->label(__('lunar-cjdropshipping::admin.links.actions.sync'))
                    ->icon('heroicon-o-arrow-path')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (EloquentCollection $records) => static::queueSync($records)),
            ]);
    }

    public static function getDefaultPages(): array
    {
        return [
            'index' => Pages\ListProductLinks::route('/'),
        ];
    }
}
```

`src/Filament/Resources/ProductLinkResource/Pages/ListProductLinks.php`:
```php
<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources\ProductLinkResource\Pages;

use Lunar\Admin\Support\Pages\BaseListRecords;
use Thayron\LunarCjDropshipping\Filament\Resources\ProductLinkResource;

class ListProductLinks extends BaseListRecords
{
    protected static string $resource = ProductLinkResource::class;
}
```

- [ ] **Step 5: Registrar no plugin**

Em `CjDropshippingPlugin::register()`:
```php
        $panel->resources([
            ImportRuleResource::class,
            CandidateResource::class,
            ProductLinkResource::class,
        ]);
```
(com os `use` correspondentes).

- [ ] **Step 6: Rodar e ver passar**

Run: `vendor/bin/phpunit tests/Filament`
Expected: `OK (12 tests, ...)`. Depois `vendor/bin/phpunit` → `OK`.

Nota: se o JSON vazio for gravado no SQLite como `[]` e o filtro `new_variants` não bater por diferença de formatação, troque a condição por `whereJsonLength('new_cj_variant_ids', '>', 0)` e registre.

- [ ] **Step 7: Commit**

```bash
git add src tests/Filament
git commit -m "feat: add candidates and CJ products admin screens" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

---

### Task 14: Teste live, qualidade e instalação no lunar

**Files:**
- Create: `tests/Integration/LiveImportTest.php`
- Modify: arquivos de `src/` apontados pelo Larastan (só tipos/PHPDoc, sem mudar comportamento)
- Modify (app lunar, sem commit): `C:\laraenv\www\lunar\composer.json`, `composer.lock`, `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: todas as tasks anteriores.
- Produces: package instalado no app lunar, com migrations aplicadas e o plugin registrado no painel.

- [ ] **Step 1: Escrever o teste live**

`tests/Integration/LiveImportTest.php`:
```php
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
```

- [ ] **Step 2: Confirmar exclusão da suíte padrão**

Run: `vendor/bin/phpunit` → `OK` (sem executar `LiveImportTest`). Run: `vendor/bin/phpunit --group live` sem `CJ_API_KEY` → teste pulado.

- [ ] **Step 3: Larastan e Pint**

Run: `composer analyse` → corrigir até `[OK] No errors` (apenas tipos/PHPDoc; nunca ignore erros com baseline sem registrar no relatório). Run: `composer format`, depois `vendor/bin/phpunit` → `OK`.

- [ ] **Step 4: Commit do package**

```bash
git add -A
git commit -m "test: add live import test and static analysis fixes" -m "Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PM2YNtdYm3owoDmHrMKCZw"
```

- [ ] **Step 5: Instalar no app lunar**

Em `C:\laraenv\www\lunar`:
1. `composer require thayron/lunar-cjdropshipping:@dev --no-interaction` → espera `Symlinking from packages/lunar-cjdropshipping`.
2. Em `app/Providers/AppServiceProvider.php`, adicione `\Thayron\LunarCjDropshipping\Filament\CjDropshippingPlugin::make()` ao array `->plugins([...])` já existente em `LunarPanel::panel(...)` (mantendo `ShippingPlugin` e `StorefrontThemesPlugin`).
3. `php artisan migrate --no-interaction` → cria `cj_import_rules`, `cj_candidates`, `cj_product_links`, `cj_variant_links`.
4. `php artisan route:list --path=cjdropshipping` → mostra `POST cjdropshipping/webhook`.
5. `php artisan list cj` → mostra `cj:discover`, `cj:sync`, `cj:webhooks:setup`.

Não faça commit no repositório lunar (há alterações do usuário não commitadas). Informe no relatório os arquivos alterados no app.
