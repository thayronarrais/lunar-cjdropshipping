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

    /**
     * @return Builder<Candidate>
     */
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
                    ->action(fn (EloquentCollection $records) => static::approve($records->filter(fn (Model $model): bool => $model instanceof Candidate))),
                Tables\Actions\BulkAction::make('ignore')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.ignore'))
                    ->icon('heroicon-o-eye-slash')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (EloquentCollection $records) => $records
                        ->filter(fn (Model $model): bool => $model instanceof Candidate)
                        ->filter(fn (Candidate $candidate) => in_array($candidate->status, [CandidateStatus::Pending, CandidateStatus::Failed], true))
                        ->each(fn (Candidate $candidate) => $candidate->forceFill(['status' => CandidateStatus::Ignored])->save())),
                Tables\Actions\BulkAction::make('reset')
                    ->label(__('lunar-cjdropshipping::admin.candidates.actions.reset'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (EloquentCollection $records) => $records
                        ->filter(fn (Model $model): bool => $model instanceof Candidate)
                        ->filter(fn (Candidate $candidate) => in_array($candidate->status, [CandidateStatus::Ignored, CandidateStatus::Failed, CandidateStatus::Approved, CandidateStatus::Importing], true))
                        ->each(fn (Candidate $candidate) => $candidate->forceFill(['status' => CandidateStatus::Pending, 'error' => null])->save())),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getDefaultPages(): array
    {
        return [
            'index' => Pages\ListCandidates::route('/'),
        ];
    }
}
