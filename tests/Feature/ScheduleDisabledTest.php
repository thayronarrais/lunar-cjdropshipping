<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ScheduleDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('lunar-cjdropshipping.schedule.enabled', false);
    }

    public function test_does_not_schedule_when_disabled(): void
    {
        $events = [];

        foreach ($this->app->make(Schedule::class)->events() as $event) {
            foreach (['cj:discover', 'cj:sync'] as $command) {
                if (str_contains((string) $event->command, $command)) {
                    $events[$command] = $event;
                }
            }
        }

        $this->assertSame([], $events);
    }
}
