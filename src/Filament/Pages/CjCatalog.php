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

        $this->getForm('form')?->fill();
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

        $products = $result->items;
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
