<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Tests\Feature\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use PHPUnit\Framework\Attributes\DataProvider;
use Thayron\LunarCjDropshipping\Jobs\DiscoverCandidatesJob;
use Thayron\LunarCjDropshipping\Jobs\ImportProductImagesJob;
use Thayron\LunarCjDropshipping\Jobs\ImportProductJob;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Models\ImportRule;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Tests\TestCase;

final class JobPoliciesTest extends TestCase
{
    /**
     * @return array<string, array{0: callable(): object, 1: int}>
     */
    public static function cjJobs(): array
    {
        return [
            'discover' => [fn (): object => new DiscoverCandidatesJob(new ImportRule), 3600],
            'import' => [fn (): object => new ImportProductJob(new Candidate), 600],
            'sync' => [fn (): object => new SyncProductJob(new ProductLink), 300],
        ];
    }

    #[DataProvider('cjJobs')]
    public function test_cj_jobs_retry_by_time_and_cap_real_exceptions(callable $make, int $timeout): void
    {
        $job = $make();

        $this->assertFalse(property_exists($job, 'tries'));
        $this->assertSame(3, $job->maxExceptions);
        $this->assertTrue($job->retryUntil() > now()->addHours(47));
        $this->assertSame($timeout, $job->timeout);
        $this->assertSame(90000, $job->uniqueFor);
    }

    public function test_image_job_keeps_a_short_unique_lock_and_has_a_timeout(): void
    {
        $job = new ImportProductImagesJob(new ProductLink, []);

        $this->assertSame(3600, $job->uniqueFor);
        $this->assertSame(900, $job->timeout);
    }

    public function test_sync_job_is_unique_only_until_processing(): void
    {
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, new SyncProductJob(new ProductLink));
    }
}
