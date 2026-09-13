<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Jobs;

use DateTimeInterface;
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
use Thayron\LunarCjDropshipping\Actions\ImportNewVariants;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\CjLog;
use Thayron\LunarCjDropshipping\Support\QuotaDelay;
use Throwable;

final class ImportNewVariantsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $maxExceptions = 3;

    public int $timeout = 600;

    public int $uniqueFor = 90000;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public ProductLink $link)
    {
        $this->onQueue((string) config('lunar-cjdropshipping.queue'));
    }

    public function uniqueId(): string
    {
        return 'cj-new-variants-'.$this->link->cj_product_id;
    }

    /**
     * Quota releases do not consume attempts; only real exceptions are capped by $maxExceptions.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(48);
    }

    public function handle(ImportNewVariants $importNewVariants): void
    {
        try {
            $importNewVariants->handle($this->link);
        } catch (QuotaExceededException) {
            $this->release(QuotaDelay::seconds());
        } catch (RateLimitException|ServerException|TransportException $exception) {
            $this->link->forceFill(['sync_error' => $exception->getMessage()])->save();

            throw $exception;
        } catch (Throwable $exception) {
            $this->link->forceFill(['sync_error' => $exception->getMessage()])->save();
            CjLog::channel()->error('CJ new variants import failed', ['cj_product_id' => $this->link->cj_product_id, 'exception' => $exception]);
        }
    }
}
