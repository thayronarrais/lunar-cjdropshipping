<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * @return Builder<ProductLink>
     */
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
                    ->query(fn (Builder $query): Builder => $query->whereJsonLength('new_cj_variant_ids', '>', 0)),
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
                    ->action(fn (EloquentCollection $records) => static::queueSync($records->filter(fn (Model $model): bool => $model instanceof ProductLink))),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getDefaultPages(): array
    {
        return [
            'index' => Pages\ListProductLinks::route('/'),
        ];
    }
}
