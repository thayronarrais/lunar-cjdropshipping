<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Actions;

use Illuminate\Support\Facades\Cache;
use Thayron\CjDropshipping\CjClient;
use Thayron\CjDropshipping\Criteria\ProductSearch;
use Thayron\CjDropshipping\Data\Paginated;
use Thayron\CjDropshipping\Data\ProductSummary;
use Thayron\LunarCjDropshipping\Support\Throttle;

final class SearchCatalog
{
    public const PER_PAGE = 24;

    private const TTL = 600;

    public function __construct(
        private readonly CjClient $cj,
        private readonly Throttle $throttle,
    ) {}

    /**
     * @param  array{keyword?: string|null, category_id?: string|null, country_code?: string|null, min_price?: string|null, max_price?: string|null}  $filters
     * @return Paginated<ProductSummary>
     */
    public function handle(array $filters, int $page): Paginated
    {
        $criteria = ProductSearch::make()->perPage(self::PER_PAGE)->page(max(1, min($page, ProductSearch::MAX_PAGE)));

        if (filled($filters['keyword'] ?? null)) {
            $criteria = $criteria->keyword((string) $filters['keyword']);
        }

        if (filled($filters['category_id'] ?? null)) {
            $criteria = $criteria->category((string) $filters['category_id']);
        }

        if (filled($filters['country_code'] ?? null)) {
            $criteria = $criteria->country((string) $filters['country_code']);
        }

        $min = filled($filters['min_price'] ?? null) ? (string) $filters['min_price'] : null;
        $max = filled($filters['max_price'] ?? null) ? (string) $filters['max_price'] : null;

        if ($min !== null || $max !== null) {
            $criteria = $criteria->priceBetween($min, $max);
        }

        /** @var Paginated<ProductSummary> */
        return Cache::remember('lunar-cjdropshipping.catalog.'.md5((string) json_encode($criteria->toQuery())), self::TTL, function () use ($criteria): Paginated {
            $this->throttle->wait();

            return $this->cj->products()->search($criteria);
        });
    }
}
