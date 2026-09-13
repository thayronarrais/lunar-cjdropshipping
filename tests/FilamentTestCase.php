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
