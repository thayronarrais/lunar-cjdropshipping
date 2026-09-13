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
