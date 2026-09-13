<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests;

use Awcodes\FilamentBadgeableColumn\BadgeableColumnServiceProvider;
use Awcodes\Shout\ShoutServiceProvider;
use Barryvdh\DomPDF\ServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Leandrocfe\FilamentApexCharts\FilamentApexChartsServiceProvider;
use Livewire\LivewireServiceProvider;
use Lunar\Admin\LunarPanelProvider;
use Lunar\Admin\Models\Staff;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\LaravelPasskeys\LaravelPasskeysServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;
use Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticationServiceProvider;
use Technikermathe\LucideIcons\BladeLucideIconsServiceProvider;
use Thayron\LunarCjDropshipping\Tests\Support\TestPanelProvider;

abstract class FilamentTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        $filament = array_values(array_filter([
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeLucideIconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            PowerJoinsServiceProvider::class,
            FilamentServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            BadgeableColumnServiceProvider::class,
            ShoutServiceProvider::class,
            FilamentApexChartsServiceProvider::class,
            TwoFactorAuthenticationServiceProvider::class,
            LaravelPasskeysServiceProvider::class,
            ServiceProvider::class,
            PermissionServiceProvider::class,
        ], fn (string $provider): bool => class_exists($provider)));

        return [
            ...$filament,
            ...parent::getPackageProviders($app),
            LunarPanelProvider::class,
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
