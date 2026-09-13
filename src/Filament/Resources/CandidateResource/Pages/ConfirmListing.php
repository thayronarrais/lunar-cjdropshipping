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
        static::authorizeResourceAccess();

        $this->record = $this->resolveRecord($record);
        $candidate = $this->candidate();

        if (! in_array($candidate->status, [CandidateStatus::Pending, CandidateStatus::Failed], true)) {
            $this->loadError = __('lunar-cjdropshipping::admin.listing.errors.not_confirmable');
            $this->getForm('form')?->fill();

            return;
        }

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
            $this->getForm('form')?->fill();

            return;
        } catch (Throwable $exception) {
            $this->loadError = __('lunar-cjdropshipping::admin.listing.load_failed', ['message' => $exception->getMessage()]);
            $this->getForm('form')?->fill();

            return;
        }

        $this->shipFromOptions = $this->stockCountries($inventory);

        /** @var array<string, ListingVariant> $listed */
        $listed = [];

        foreach ($listing === null ? [] : $listing->variants as $listedVariant) {
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

        $this->getForm('form')?->fill($listing !== null ? [
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
            'product_type_id' => $rule !== null ? $rule->product_type_id : ProductType::query()->value('id'),
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
                    ->required()->live()->afterStateUpdated(fn () => $this->clearPrices()),
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
                    ->options(function (): array {
                        /** @var \Illuminate\Database\Eloquent\Collection<int, Collection> $collections */
                        $collections = Collection::query()->get();

                        return $collections->mapWithKeys(fn (Collection $collection): array => [$collection->id => (string) $collection->translateAttribute('name')])->all();
                    })
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
        $this->getForm('form')?->validate();

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

    private function clearPrices(): void
    {
        foreach (array_keys($this->variants) as $index) {
            $this->variants[$index]['price'] = null;
        }

        Notification::make()->title(__('lunar-cjdropshipping::admin.listing.prices_cleared'))->warning()->send();
    }

    /**
     * Methods common to every selected variant's quote, so choosing one never leaves a
     * selected variant unshippable. Labelled with the lowest–highest price among them.
     *
     * @return array<string, string> method name => "name — US$ price — aging days"
     */
    public function methodOptions(): array
    {
        $selected = array_values(array_filter($this->variants, fn (array $row): bool => $row['selected']));

        if ($selected === []) {
            return [];
        }

        /** @var array<string, array<string, array{price_usd: string|null, aging: string|null}>> $quote */
        $quote = [];
        $vids = [];

        foreach ($selected as $row) {
            $quote[$row['vid']] = $row['methods'];
            $vids[] = $row['vid'];
        }

        $options = [];

        foreach (FreightQuoter::commonMethods($quote, $vids) as $name) {
            $prices = [];
            $aging = null;

            foreach ($selected as $row) {
                $price = $row['methods'][$name]['price_usd'] ?? null;

                if ($price !== null) {
                    $prices[] = $price;
                }

                $aging ??= $row['methods'][$name]['aging'] ?? null;
            }

            $options[$name] = __('lunar-cjdropshipping::admin.listing.method_option', [
                'name' => $name,
                'price' => $this->priceRangeLabel($prices),
                'aging' => $aging ?? '—',
            ]);
        }

        return $options;
    }

    /**
     * @param  list<string>  $prices
     */
    private function priceRangeLabel(array $prices): string
    {
        if ($prices === []) {
            return '—';
        }

        $min = $prices[0];
        $max = $prices[0];

        foreach ($prices as $price) {
            if (bccomp($price, $min, 2) < 0) {
                $min = $price;
            }

            if (bccomp($price, $max, 2) > 0) {
                $max = $price;
            }
        }

        return bccomp($min, $max, 2) === 0 ? $min : "{$min}–{$max}";
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
