<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Thayron\LunarCjDropshipping\LunarCjDropshippingServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const API_KEY = 'test-api-key';

    protected function getPackageProviders($app): array
    {
        return [
            \Spatie\LaravelBlink\BlinkServiceProvider::class,
            \Cartalyst\Converter\Laravel\ConverterServiceProvider::class,
            \Kalnoy\Nestedset\NestedSetServiceProvider::class,
            \Spatie\MediaLibrary\MediaLibraryServiceProvider::class,
            \Spatie\Activitylog\ActivitylogServiceProvider::class,
            \Lunar\LunarServiceProvider::class,
            \Thayron\CjDropshipping\Laravel\CjDropshippingServiceProvider::class,
            LunarCjDropshippingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.discard', ['driver' => 'null']);
        $app['config']->set('media-library.disk_name', 'public');
        $app['config']->set('media-library.queue_connection_name', 'discard');
        $app['config']->set('cjdropshipping.api_key', self::API_KEY);
        $app['config']->set('lunar-cjdropshipping.requests_per_second', 0);
        $app['config']->set('lunar-cjdropshipping.schedule.enabled', false);
    }
}
