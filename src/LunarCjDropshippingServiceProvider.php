<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping;

use Illuminate\Support\ServiceProvider;

final class LunarCjDropshippingServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../config/lunar-cjdropshipping.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'lunar-cjdropshipping');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => $this->app->configPath('lunar-cjdropshipping.php')], 'lunar-cjdropshipping-config');
        }
    }
}
