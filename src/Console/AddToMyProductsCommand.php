<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Exceptions\CjException;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\CjLog;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Throwable;

final class AddToMyProductsCommand extends Command
{
    protected $signature = 'cj:add-to-my-products {--product= : Only this CJ product id}';

    protected $description = 'Add imported CJdropshipping products to the CJ account My Products panel';

    public function handle(CjClient $cj, Throttle $throttle): int
    {
        $query = ProductLink::query()->whereHas('product');
        $productId = $this->option('product');

        if (is_string($productId) && $productId !== '') {
            $query->where('cj_product_id', $productId);
        }

        $added = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById(100, function (Collection $links) use ($cj, $throttle, &$added, &$skipped, &$failed): void {
            foreach ($links as $link) {
                try {
                    $throttle->wait();

                    if ($cj->products()->addToMyProducts($link->cj_product_id)) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                } catch (CjException $exception) {
                    $failed++;
                    CjLog::channel()->warning('CJ add to My Products failed', ['cj_product_id' => $link->cj_product_id, 'message' => $exception->getMessage()]);
                } catch (Throwable $exception) {
                    $failed++;
                    CjLog::channel()->warning('CJ add to My Products failed unexpectedly', ['cj_product_id' => $link->cj_product_id, 'message' => $exception->getMessage()]);
                }
            }
        });

        $this->info("Added {$added}, not added {$skipped}, failed {$failed}.");

        if ($failed > 0 && $added === 0 && $skipped === 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
