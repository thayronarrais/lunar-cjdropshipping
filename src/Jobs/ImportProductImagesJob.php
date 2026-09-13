<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Thayron\LunarCjDropshipping\Actions\ImportProductImages;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;
use Thayron\LunarCjDropshipping\Models\ProductLink;

final class ImportProductImagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $uniqueFor = 3600;

    /**
     * @param  list<string>  $urls
     */
    public function __construct(public ProductLink $link, public array $urls)
    {
        $this->onQueue((string) config('lunar-cjdropshipping.queue'));
    }

    public function uniqueId(): string
    {
        return 'cj-images-'.$this->link->id;
    }

    public function handle(ImportProductImages $images): void
    {
        $failures = $images->handle($this->link, $this->urls);

        if ($failures !== []) {
            throw new ImportException(count($failures).' image(s) failed to import for CJ product '.$this->link->cj_product_id.'.');
        }
    }
}
