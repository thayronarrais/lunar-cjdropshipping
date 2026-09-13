<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource\Pages;

use Filament\Actions;
use Lunar\Admin\Support\Pages\BaseListRecords;
use Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource;

class ListImportRules extends BaseListRecords
{
    protected static string $resource = ImportRuleResource::class;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getDefaultHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
