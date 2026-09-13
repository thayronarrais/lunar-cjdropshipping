<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests;

use Cartalyst\Converter\Laravel\ConverterServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kalnoy\Nestedset\NestedSetServiceProvider;
use Lunar\LunarServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelBlink\BlinkServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Thayron\CjDropshipping\Laravel\CjDropshippingServiceProvider;
use Thayron\LunarCjDropshipping\LunarCjDropshippingServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const API_KEY = 'test-api-key';

    protected function getPackageProviders($app): array
    {
        return [
            BlinkServiceProvider::class,
            ConverterServiceProvider::class,
            NestedSetServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ActivitylogServiceProvider::class,
            LunarServiceProvider::class,
            CjDropshippingServiceProvider::class,
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
