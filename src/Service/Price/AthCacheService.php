<?php

namespace App\Service\Price;

use App\Dto\AthInfo;
use App\Enum\Pair;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Separate from PriceCacheService: refreshed daily, not hourly - an ATH
 * doesn't move often enough to justify polling it on the price cadence.
 */
final class AthCacheService
{
    // A day plus slack, matching the daily refresh cadence.
    private const int TTL_SECONDS = 90000;

    // Bump whenever AthInfo's shape changes so stale-shaped cache entries are
    // never unserialized into it - see project_cryptopulse_ath_cache_gotcha.
    private const string SCHEMA_VERSION = 'v1';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function write(AthInfo $ath): void
    {
        $item = $this->cache->getItem(self::key($ath->pair));
        $item->set($ath);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    public function read(Pair $pair): ?AthInfo
    {
        $item = $this->cache->getItem(self::key($pair));

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Single round trip for all pairs, instead of one read per pair.
     *
     * @param list<Pair> $pairs
     *
     * @return array<string, AthInfo> keyed by Pair::value, misses omitted
     */
    public function readMany(array $pairs): array
    {
        $items = iterator_to_array($this->cache->getItems(array_map(self::key(...), $pairs)));

        $athByPair = [];
        foreach ($pairs as $pair) {
            $item = $items[self::key($pair)] ?? null;
            if ($item?->isHit()) {
                $athByPair[$pair->value] = $item->get();
            }
        }

        return $athByPair;
    }

    private static function key(Pair $pair): string
    {
        return 'price.ath.'.self::SCHEMA_VERSION.'.'.$pair->value;
    }
}
