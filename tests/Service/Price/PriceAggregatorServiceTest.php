<?php

namespace App\Tests\Service\Price;

use App\Dto\PriceQuote;
use App\Enum\Exchange;
use App\Enum\Pair;
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
}
