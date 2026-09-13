<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Media;

use Illuminate\Support\Facades\Http;
use Thayron\LunarCjDropshipping\Exceptions\ImportException;

final class HttpImageDownloader implements ImageDownloader
{
    public function download(string $url): string
    {
        $response = Http::timeout(30)->get($url)->throw();

        $extension = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cj-'.bin2hex(random_bytes(8)).'.'.strtolower($extension);

        if (file_put_contents($path, $response->body()) === false) {
            throw new ImportException("Could not write downloaded image [{$url}].");
        }

        return $path;
    }
}
