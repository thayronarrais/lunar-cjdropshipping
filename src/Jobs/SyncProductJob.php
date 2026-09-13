<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Thayron\CjDropshipping\Exceptions\QuotaExceededException;
use Thayron\CjDropshipping\Exceptions\RateLimitException;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\CjDropshipping\Exceptions\TransportException;
use Thayron\LunarCjDropshipping\Actions\SyncProduct;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\CjLog;
use Thayron\LunarCjDropshipping\Support\QuotaDelay;
use Throwable;

final class SyncProductJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public ProductLink $link)
    {
        $this->onQueue((string) config('lunar-cjdropshipping.queue'));
    }

    public function uniqueId(): string
    {
        return 'cj-sync-'.$this->link->cj_product_id;
    }

    public function handle(SyncProduct $sync): void
    {
        try {
            $sync->handle($this->link);
        } catch (QuotaExceededException) {
            $this->release(QuotaDelay::seconds());
        } catch (RateLimitException|ServerException|TransportException $exception) {
            $this->link->forceFill(['sync_error' => $exception->getMessage()])->save();

            throw $exception;
        } catch (Throwable $exception) {
            $this->link->forceFill(['sync_error' => $exception->getMessage()])->save();
            CjLog::channel()->error('CJ sync failed', ['cj_product_id' => $this->link->cj_product_id, 'exception' => $exception]);
        }
    }
}
