<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Logistics;

use Illuminate\Support\Facades\Cache;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Criteria\FreightQuery;
use Thayron\CjDropshipping\Data\FreightOption;
use Thayron\LunarCjDropshipping\Support\Throttle;

/**
 * Shipping quotes per variant. Variants with the same weight share one CJ call (10 quota points each).
 */
final class FreightQuoter
{
    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
    ) {}

    /**
     * @param  array<array-key, string|null>  $weightsByVid  vid => weight in grams
     * @return array<array-key, array<string, FreightOption>> vid => method name => option
     */
    public function quote(string $productId, string $from, string $to, array $weightsByVid): array
    {
        $key = 'lunar-cjdropshipping.freight.'.md5((string) json_encode([$productId, strtoupper($from), strtoupper($to), $weightsByVid]));

        /** @var array<array-key, array<string, FreightOption>> */
        return Cache::remember($key, (int) config('lunar-cjdropshipping.freight.cache_ttl', 21600), function () use ($from, $to, $weightsByVid): array {
            /** @var array<string, list<string>> $groups */
            $groups = [];

            foreach ($weightsByVid as $vid => $weight) {
                $groups[(string) ($weight ?? '')][] = (string) $vid;
            }

            $quote = [];

            foreach ($groups as $vids) {
                $this->throttle->wait();
                $byName = [];

                foreach ($this->cj->logistics()->freightCalculate(FreightQuery::make($from, $to)->product($vids[0])) as $option) {
                    $byName[$option->name] = $option;
                }

                foreach ($vids as $vid) {
                    $quote[$vid] = $byName;
                }
            }

            return $quote;
        });
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $quote  vid => method name => anything
     * @param  list<string>  $vids
     * @return list<string>
     */
    public static function commonMethods(array $quote, array $vids): array
    {
        if ($vids === []) {
            return [];
        }

        $methods = null;

        foreach ($vids as $vid) {
            $names = array_map('strval', array_keys($quote[$vid] ?? []));
            $methods = $methods === null ? $names : array_values(array_filter($methods, fn (string $name): bool => in_array($name, $names, true)));
        }

        return $methods;
    }
}
