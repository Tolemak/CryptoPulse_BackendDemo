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
    private const int TTL_SECONDS = 3900;

    private const string SCHEMA_VERSION = 'v1';

    /** @var PairObjectCache<AggregatedPrice> */
    private readonly PairObjectCache $store;

    public function __construct(CacheItemPoolInterface $cache)
    {
        $this->store = new PairObjectCache($cache, 'price.aggregate.'.self::SCHEMA_VERSION, AggregatedPrice::class, self::TTL_SECONDS);
    }

    public function write(AggregatedPrice $price): void
    {
        $this->store->write($price->pair, $price);
    }

    public function read(Pair $pair): ?AggregatedPrice
    {
        return $this->store->read($pair);
    }

    /**
     * @param list<Pair> $pairs
     *
     * @return array<string, AggregatedPrice> keyed by Pair::value, misses omitted
     */
    public function readMany(array $pairs): array
    {
        return $this->store->readMany($pairs);
    }
}
