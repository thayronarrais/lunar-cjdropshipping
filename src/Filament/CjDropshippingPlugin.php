<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Thayron\LunarCjDropshipping\Filament\Pages\CjCatalog;
use Thayron\LunarCjDropshipping\Filament\Resources\CandidateResource;
use Thayron\LunarCjDropshipping\Filament\Resources\ImportRuleResource;
use Thayron\LunarCjDropshipping\Filament\Resources\ProductLinkResource;

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
            CandidateResource::class,
            ProductLinkResource::class,
        ]);

        $panel->pages([
            CjCatalog::class,
        ]);
    }

    public function boot(Panel $panel): void {}

    public static function make(): self
    {
        return app(self::class);
    }
}
