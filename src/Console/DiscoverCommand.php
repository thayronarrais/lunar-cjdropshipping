<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Console;

use Illuminate\Console\Command;
use Thayron\LunarCjDropshipping\Jobs\DiscoverCandidatesJob;
use Thayron\LunarCjDropshipping\Models\ImportRule;

final class DiscoverCommand extends Command
{
    protected $signature = 'cj:discover {--rule=* : Import rule ids (defaults to all active rules)}';

    protected $description = 'Queue CJdropshipping candidate discovery for import rules';

    public function handle(): int
    {
        $ids = array_filter((array) $this->option('rule'));

        $rules = ImportRule::query()
            ->when($ids !== [], fn ($query) => $query->whereKey($ids), fn ($query) => $query->where('is_active', true))
            ->get();

        $rules->each(fn (ImportRule $rule) => DiscoverCandidatesJob::dispatch($rule));

        $this->info("Dispatched {$rules->count()} discovery job(s).");

        return self::SUCCESS;
    }
}
