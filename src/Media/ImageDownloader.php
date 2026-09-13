<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Media;

interface ImageDownloader
{
    /**
     * Download an image and return the path of a local temporary file.
     */
    public function download(string $url): string;
}
