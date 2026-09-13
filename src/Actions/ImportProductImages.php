<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Thayron\LunarCjDropshipping\Media\ImageDownloader;
use Thayron\LunarCjDropshipping\Models\ProductLink;
use Thayron\LunarCjDropshipping\Support\MediaCollection;
use Throwable;

final class ImportProductImages
{
    public function __construct(private readonly ImageDownloader $downloader) {}

    /**
     * @param  array<array-key, string>  $urls
     * @return list<string> failures as "url: message"
     */
    public function handle(ProductLink $link, array $urls): array
    {
        $product = $link->product;

        if ($product === null) {
            return [];
        }

        // Force a fresh read: Spatie caches the "media" relation on the model
        // instance, which would hide media added by an earlier call on the
        // same $product object (e.g. a re-run within the same request/test).
        $product->unsetRelation('media');

        $collection = MediaCollection::name();
        $media = $product->getMedia($collection);
        $existing = $media->map(fn ($item) => $item->getCustomProperty('cj_source_url'))->filter()->all();
        $hasPrimary = $media->contains(fn ($item) => (bool) $item->getCustomProperty('primary'));
        $failures = [];

        foreach (array_values(array_unique($urls)) as $url) {
            if (in_array($url, $existing, true)) {
                continue;
            }

            try {
                $path = $this->downloader->download($url);

                $product->addMedia($path)
                    ->withCustomProperties(['cj_source_url' => $url, 'primary' => ! $hasPrimary])
                    ->toMediaCollection($collection);

                $hasPrimary = true;
            } catch (Throwable $exception) {
                $failures[] = "{$url}: {$exception->getMessage()}";
            }
        }

        $link->forceFill(['sync_error' => $failures === [] ? null : 'Image import failed: '.implode('; ', $failures)])->save();

        return $failures;
    }
}
