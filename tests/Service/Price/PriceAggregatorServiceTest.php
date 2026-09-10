<?php

namespace App\Tests\Service\Price;

use App\Dto\PriceQuote;
use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Exchange\BulkFetchingExchangeClientInterface;
use App\Service\Exchange\ExchangeClientInterface;
use App\Service\Price\PriceAggregatorService;
use PHPUnit\Framework\TestCase;

final class StubExchangeClient implements ExchangeClientInterface
{
    public function __construct(
        private readonly Exchange $exchangeName,
        private readonly ?float $price,
    ) {
    }

    public function exchange(): Exchange
    {
        return $this->exchangeName;
    }

    public function fetchPrice(Pair $pair): ?PriceQuote
    {
        return null === $this->price
            ? null
            : new PriceQuote($this->exchangeName, $pair, $this->price, new \DateTimeImmutable());
    }
}

final class StubBulkExchangeClient implements BulkFetchingExchangeClientInterface
{
    /** @var Pair[] */
    public array $requestedPairs = [];

    /**
     * @param array<string, float> $pricesByPairValue
     */
    public function __construct(
        private readonly Exchange $exchangeName,
        private readonly array $pricesByPairValue,
    ) {
    }

    public function exchange(): Exchange
    {
        return $this->exchangeName;
    }

    public function fetchPrice(Pair $pair): ?PriceQuote
    {
        return $this->fetchPrices([$pair])[$pair->value] ?? null;
    }

    public function fetchPrices(array $pairs): array
    {
        $this->requestedPairs = $pairs;
        $result = [];
        foreach ($pairs as $pair) {
            $price = $this->pricesByPairValue[$pair->value] ?? null;
            $result[$pair->value] = null === $price
                ? null
                : new PriceQuote($this->exchangeName, $pair, $price, new \DateTimeImmutable());
        }

        return $result;
    }
}

final class PriceAggregatorServiceTest extends TestCase
{
    public function testMedianOfThreeQuotes(): void
    {
        $aggregator = new PriceAggregatorService([
            new StubExchangeClient(Exchange::Binance, 100.0),
            new StubExchangeClient(Exchange::Kraken, 102.0),
            new StubExchangeClient(Exchange::Coinbase, 101.0),
        ]);

        $result = $aggregator->aggregate(Pair::BTC_USD);

        self::assertNotNull($result);
        self::assertSame(101.0, $result->median);
        self::assertCount(3, $result->breakdown);
    }

    public function testMedianOfTwoQuotesAverages(): void
    {
        $aggregator = new PriceAggregatorService([
            new StubExchangeClient(Exchange::Binance, 100.0),
            new StubExchangeClient(Exchange::Kraken, 200.0),
        ]);

        $result = $aggregator->aggregate(Pair::BTC_USD);

        self::assertNotNull($result);
        self::assertSame(150.0, $result->median);
    }

    public function testToleratesOneExchangeFailing(): void
    {
        $aggregator = new PriceAggregatorService([
            new StubExchangeClient(Exchange::Binance, 100.0),
            new StubExchangeClient(Exchange::Kraken, null),
        ]);

        $result = $aggregator->aggregate(Pair::BTC_USD);

        self::assertNotNull($result);
        self::assertSame(100.0, $result->median);
        self::assertCount(1, $result->breakdown);
    }

    public function testNullWhenEveryExchangeFails(): void
    {
        $aggregator = new PriceAggregatorService([
            new StubExchangeClient(Exchange::Binance, null),
            new StubExchangeClient(Exchange::Kraken, null),
        ]);

        self::assertNull($aggregator->aggregate(Pair::BTC_USD));
    }

    public function testAggregateAllQueriesABulkClientOnceForEveryPair(): void
    {
        $bulkClient = new StubBulkExchangeClient(Exchange::Coinbase, [
            'BTC_USD' => 65000.0,
            'ETH_USD' => 3200.0,
        ]);
        $aggregator = new PriceAggregatorService([$bulkClient]);

        $results = $aggregator->aggregateAll([Pair::BTC_USD, Pair::ETH_USD]);

        self::assertCount(2, $bulkClient->requestedPairs, 'fetchPrices should be called once with both pairs, not once per pair');
        self::assertCount(2, $results);
    }

    public function testAggregateAllCombinesBulkAndSequentialClients(): void
    {
        $aggregator = new PriceAggregatorService([
            new StubBulkExchangeClient(Exchange::Coinbase, ['BTC_USD' => 65000.0, 'ETH_USD' => 3200.0]),
            new StubExchangeClient(Exchange::Binance, 65100.0),
        ]);

        $results = $aggregator->aggregateAll([Pair::BTC_USD, Pair::ETH_USD]);
        $byPair = [];
        foreach ($results as $result) {
            $byPair[$result->pair->value] = $result;
        }

        // The sequential (non-bulk) client is asked about every pair in the
        // list in turn, so both pairs pick up a quote from each client.
        self::assertCount(2, $byPair['BTC_USD']->breakdown);
        self::assertCount(2, $byPair['ETH_USD']->breakdown);
    }

    public function testAggregateAllSkipsPairsNoClientReturnedAPriceFor(): void
    {
        $bulkClient = new StubBulkExchangeClient(Exchange::Coinbase, ['BTC_USD' => 65000.0]);
        $aggregator = new PriceAggregatorService([$bulkClient]);

        $results = $aggregator->aggregateAll([Pair::BTC_USD, Pair::ETH_USD]);

        self::assertCount(1, $results);
        self::assertSame(Pair::BTC_USD, $results[0]->pair);
    }
}
