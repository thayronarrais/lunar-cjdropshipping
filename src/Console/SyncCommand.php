<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Thayron\LunarCjDropshipping\Jobs\SyncProductJob;
use Thayron\LunarCjDropshipping\Models\ProductLink;

final class SyncCommand extends Command
{
    protected $signature = 'cj:sync
        {--all : Sync every linked product}
        {--product= : Sync a single CJ product id}';

    protected $description = 'Queue CJdropshipping stock and price sync for linked products';

    public function handle(): int
    {
        $query = ProductLink::query();
        $productId = $this->option('product');

        if (is_string($productId) && $productId !== '') {
            $query->where('cj_product_id', $productId);
        } elseif (! $this->option('all')) {
            $staleBefore = now()->subHours((int) config('lunar-cjdropshipping.sync.stale_after_hours', 6));
            $query->where(fn ($where) => $where->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $staleBefore));
        }

        $count = 0;

        $query->chunkById(200, function (Collection $links) use (&$count): void {
            foreach ($links as $link) {
                SyncProductJob::dispatch($link);
                $count++;
            }
        });

        $this->info("Dispatched {$count} sync job(s).");

        return self::SUCCESS;
    }
}
