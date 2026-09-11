<?php

namespace App\Service\Price;

use App\Dto\AggregatedPrice;
use App\Enum\Pair;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Reads never trigger a recompute — a miss just means no poll has landed yet.
 */
final class PriceCacheService
{
    // Slightly over the hourly cron cadence so a late poll doesn't leave a gap.
    private const int TTL_SECONDS = 3900;

    // Bump whenever AggregatedPrice's shape changes so stale-shaped cache entries
    // are never unserialized into it - see project_cryptopulse_ath_cache_gotcha.
    private const string SCHEMA_VERSION = 'v1';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function write(AggregatedPrice $price): void
    {
        $item = $this->cache->getItem(self::key($price->pair));
        $item->set($price);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    public function read(Pair $pair): ?AggregatedPrice
    {
        $item = $this->cache->getItem(self::key($pair));

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Single round trip for all pairs, instead of one read per pair.
     *
     * @param list<Pair> $pairs
     *
     * @return array<string, AggregatedPrice> keyed by Pair::value, misses omitted
     */
    public function readMany(array $pairs): array
    {
        $items = iterator_to_array($this->cache->getItems(array_map(self::key(...), $pairs)));

        $prices = [];
        foreach ($pairs as $pair) {
            $item = $items[self::key($pair)] ?? null;
            if ($item?->isHit()) {
                $prices[$pair->value] = $item->get();
            }
        }

        return $prices;
    }

    private static function key(Pair $pair): string
    {
        return 'price.aggregate.'.self::SCHEMA_VERSION.'.'.$pair->value;
    }
}
