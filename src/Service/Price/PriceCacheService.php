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

    private static function key(Pair $pair): string
    {
        return 'price.aggregate.'.$pair->value;
    }
}
