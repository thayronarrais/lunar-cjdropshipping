<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Exceptions\NotFoundException;
use Thayron\CjDropshipping\Exceptions\QuotaExceededException;
use Thayron\CjDropshipping\Exceptions\RateLimitException;
use Thayron\CjDropshipping\Exceptions\ServerException;
use Thayron\CjDropshipping\Exceptions\TransportException;
use Thayron\LunarCjDropshipping\Actions\ImportProduct;
use Thayron\LunarCjDropshipping\Enums\CandidateStatus;
use Thayron\LunarCjDropshipping\Models\Candidate;
use Thayron\LunarCjDropshipping\Support\CjLog;
use Thayron\LunarCjDropshipping\Support\QuotaDelay;
use Thayron\LunarCjDropshipping\Support\Throttle;
use Throwable;

final class ImportProductJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public Candidate $candidate)
    {
        $this->onQueue((string) config('lunar-cjdropshipping.queue'));
    }

    public function uniqueId(): string
    {
        return 'cj-import-'.$this->candidate->cj_product_id;
    }

    public function handle(ImportProduct $import, CjClient $cj, Throttle $throttle): void
    {
        $candidate = $this->candidate;

        try {
            $result = $import->handle($candidate);
        } catch (QuotaExceededException) {
            $candidate->forceFill(['status' => CandidateStatus::Approved])->save();
            $this->release(QuotaDelay::seconds());

            return;
        } catch (NotFoundException) {
            $this->markFailed($candidate, 'Product is no longer available on CJdropshipping.');

            return;
        } catch (RateLimitException|ServerException|TransportException $exception) {
            $this->markFailed($candidate, $exception->getMessage());

            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed($candidate, $exception->getMessage());
            CjLog::channel()->error('CJ import failed', ['cj_product_id' => $candidate->cj_product_id, 'exception' => $exception]);

            return;
        }

        if ($result->imageUrls !== []) {
            ImportProductImagesJob::dispatch($result->link, $result->imageUrls);
        }

        try {
            $throttle->wait();
            $cj->webhooks()->subscribeProducts([$result->link->cj_product_id]);
        } catch (Throwable $exception) {
            CjLog::channel()->warning('CJ webhook subscription failed', ['cj_product_id' => $result->link->cj_product_id, 'message' => $exception->getMessage()]);
        }
    }

    private function markFailed(Candidate $candidate, string $message): void
    {
        $candidate->forceFill(['status' => CandidateStatus::Failed, 'error' => $message])->save();
    }
}
