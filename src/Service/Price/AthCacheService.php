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
    // never unserialized into it.
    private const string SCHEMA_VERSION = 'v1';

    /** @var PairObjectCache<AthInfo> */
    private readonly PairObjectCache $store;

    public function __construct(CacheItemPoolInterface $cache)
    {
        $this->store = new PairObjectCache($cache, 'price.ath.'.self::SCHEMA_VERSION, AthInfo::class, self::TTL_SECONDS);
    }

    public function write(AthInfo $ath): void
    {
        $this->store->write($ath->pair, $ath);
    }

    public function read(Pair $pair): ?AthInfo
    {
        return $this->store->read($pair);
    }

    /**
     * @param list<Pair> $pairs
     *
     * @return array<string, AthInfo> keyed by Pair::value, misses omitted
     */
    public function readMany(array $pairs): array
    {
        return $this->store->readMany($pairs);
    }
}
