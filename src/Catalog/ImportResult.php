<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Catalog;

use Thayron\LunarCjDropshipping\Models\ProductLink;

final readonly class ImportResult
{
    /**
     * @param  list<string>  $imageUrls  images to download (empty when the product was already linked)
     */
    public function __construct(
        public ProductLink $link,
        public array $imageUrls,
    ) {
    }
}
