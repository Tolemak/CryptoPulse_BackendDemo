<?php

namespace App\Tests\Service\Price;

use App\Dto\AggregatedPrice;
use App\Dto\AthInfo;
use App\Dto\PriceQuote;
use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Price\AthCacheService;
use App\Service\Price\PriceCacheService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PairObjectCacheTest extends TestCase
{
    public function testPriceRoundTrips(): void
    {
        $cache = new PriceCacheService(new ArrayAdapter());
        $cache->write(self::price(Pair::BTC_USD, 65000.0));

        self::assertSame(65000.0, $cache->read(Pair::BTC_USD)?->median);
        self::assertNull($cache->read(Pair::ETH_USD));
    }

    public function testAthRoundTripsThroughReadMany(): void
    {
        $cache = new AthCacheService(new ArrayAdapter());
        $cache->write(new AthInfo(Pair::BTC_USD, 126080.0, new \DateTimeImmutable('2025-10-06'), new \DateTimeImmutable(), 1000.0));

        $result = $cache->readMany([Pair::BTC_USD, Pair::ETH_USD]);

        self::assertSame(['BTC_USD'], array_keys($result));
        self::assertSame(1000.0, $result['BTC_USD']->marketCap);
    }

    public function testAnEntryOfTheWrongTypeIsAMiss(): void
    {
        $pool = new ArrayAdapter();
        $this->store($pool, 'price.aggregate.v1.BTC_USD', new \stdClass());
        $this->store($pool, 'price.ath.v1.BTC_USD', 'not an object');

        self::assertNull((new PriceCacheService($pool))->read(Pair::BTC_USD));
        self::assertNull((new AthCacheService($pool))->read(Pair::BTC_USD));
    }

    public function testAnEntryWrittenBeforeAPropertyExistedIsAMiss(): void
    {
        $pool = new ArrayAdapter();
        $stale = self::withoutConstructor(AthInfo::class, [
            'pair' => Pair::BTC_USD,
            'athPrice' => 126080.0,
            'athDate' => new \DateTimeImmutable('2025-10-06'),
            'updatedAt' => new \DateTimeImmutable(),
        ]);
        $this->store($pool, 'price.ath.v1.BTC_USD', $stale);
        $cache = new AthCacheService($pool);

        self::assertNull($cache->read(Pair::BTC_USD));
        self::assertSame([], $cache->readMany([Pair::BTC_USD]));
    }

    public function testABrokenNestedQuoteMakesTheWholeAggregateAMiss(): void
    {
        $pool = new ArrayAdapter();
        $brokenQuote = self::withoutConstructor(PriceQuote::class, [
            'exchange' => Exchange::Binance,
            'pair' => Pair::BTC_USD,
            'price' => 65000.0,
        ]);
        $this->store($pool, 'price.aggregate.v1.BTC_USD', new AggregatedPrice(Pair::BTC_USD, 65000.0, [$brokenQuote], new \DateTimeImmutable()));

        self::assertNull((new PriceCacheService($pool))->read(Pair::BTC_USD));
    }

    public function testReadManyKeepsGoodEntriesNextToBadOnes(): void
    {
        $pool = new ArrayAdapter();
        $cache = new PriceCacheService($pool);
        $cache->write(self::price(Pair::ETH_USD, 3200.0));
        $this->store($pool, 'price.aggregate.v1.BTC_USD', new \stdClass());

        self::assertSame(['ETH_USD'], array_keys($cache->readMany([Pair::BTC_USD, Pair::ETH_USD])));
    }

    private static function price(Pair $pair, float $median): AggregatedPrice
    {
        return new AggregatedPrice($pair, $median, [new PriceQuote(Exchange::Binance, $pair, $median, new \DateTimeImmutable())], new \DateTimeImmutable());
    }

    /**
     * Builds what unserialize() produces for an entry cached before the class
     * gained its remaining properties: those are left uninitialized.
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $properties
     *
     * @return T
     */
    private static function withoutConstructor(string $class, array $properties): object
    {
        $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (function (array $properties): void {
            foreach ($properties as $name => $value) {
                $this->{$name} = $value;
            }
        })->call($object, $properties);

        return $object;
    }

    private function store(ArrayAdapter $pool, string $key, mixed $value): void
    {
        $item = $pool->getItem($key);
        $item->set($value);
        $pool->save($item);
    }
}
