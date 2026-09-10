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

    private static function key(Pair $pair): string
    {
        return 'price.ath.'.$pair->value;
    }
}
