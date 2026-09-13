<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Filament\Support;

use Illuminate\Support\Facades\Cache;
use Thayron\CjDropshipping\CjClient;
use Thayron\LunarCjDropshipping\Support\CjLog;
use Throwable;

final class CjCatalogOptions
{
    private const TTL = 86400;

    /**
     * @return array<string, string> third-level category id => "Level 1 › Level 2 › Level 3"
     */
    public static function categories(): array
    {
        return self::remember('lunar-cjdropshipping.categories', function (): array {
            $options = [];
            $first = null;

            foreach (app(CjClient::class)->products()->categories() as $category) {
                if ($category->level === 1) {
                    $first = $category->name;
                }

                if ($category->level === 3 && $category->id !== null) {
                    $options[$category->id] = implode(' › ', array_filter([$first, $category->parentName, $category->name]));
                }
            }

            asort($options);

            return $options;
        });
    }

    /**
     * @return array<string, string> country code => "Country (XX)"
     */
    public static function countries(): array
    {
        return self::remember('lunar-cjdropshipping.countries', function (): array {
            $options = [];

            foreach (app(CjClient::class)->products()->warehouses() as $warehouse) {
                if ($warehouse->disabled || $warehouse->countryCode === null) {
                    continue;
                }

                $englishName = $warehouse->raw()['en'] ?? null;
                $label = $warehouse->countryName ?? (is_string($englishName) && $englishName !== '' ? $englishName : $warehouse->countryCode);

                $options[$warehouse->countryCode] = sprintf('%s (%s)', $label, $warehouse->countryCode);
            }

            asort($options);

            return $options;
        });
    }

    /**
     * @param  callable(): array<string, string>  $resolver
     * @return array<string, string>
     */
    private static function remember(string $key, callable $resolver): array
    {
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $options = $resolver();
            Cache::put($key, $options, self::TTL);

            return $options;
        } catch (Throwable $exception) {
            CjLog::channel()->warning('Could not load CJ options', ['key' => $key, 'message' => $exception->getMessage()]);

            return [];
        }
    }
}
