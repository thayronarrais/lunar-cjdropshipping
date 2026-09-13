<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class ScheduleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('lunar-cjdropshipping.schedule.enabled', true);
    }

    protected function disableSchedule($app): void
    {
        $app['config']->set('lunar-cjdropshipping.schedule.enabled', false);
    }

    protected function invalidFrequency($app): void
    {
        $app['config']->set('lunar-cjdropshipping.schedule.sync', 'whenever');
    }

    public function test_schedules_discovery_and_sync(): void
    {
        $events = $this->cjEvents();

        $this->assertSame('0 0 * * *', $events['cj:discover']->expression);
        $this->assertSame('0 */6 * * *', $events['cj:sync']->expression);
        $this->assertTrue($events['cj:sync']->withoutOverlapping);
    }

    #[DefineEnvironment('invalidFrequency')]
    public function test_invalid_frequencies_fall_back_to_daily(): void
    {
        $this->assertSame('0 0 * * *', $this->cjEvents()['cj:sync']->expression);
    }


    /**
     * @return array<string, Event>
     */
    private function cjEvents(): array
    {
        $events = [];

        foreach ($this->app->make(Schedule::class)->events() as $event) {
            foreach (['cj:discover', 'cj:sync'] as $command) {
                if (str_contains((string) $event->command, $command)) {
                    $events[$command] = $event;
                }
            }
        }

        return $events;
    }
}
