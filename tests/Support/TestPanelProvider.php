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
