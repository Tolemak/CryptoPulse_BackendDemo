<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Exchange\CoinbaseClient;
use App\Service\Exchange\PairSymbolMapper;
use App\Service\Exchange\UpstreamThrottle;
use App\Tests\Support\Upstream;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CoinbaseClientTest extends TestCase
{
    public function testFetchPricesReadsTheUsdSpotList(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertStringEndsWith('/v2/prices/USD/spot', $url);

            return new MockResponse((string) json_encode(['data' => [
                ['base' => 'BTC', 'currency' => 'USD', 'amount' => '65100.25'],
                ['base' => 'ETH', 'currency' => 'USD', 'amount' => '3200.5'],
                ['base' => 'KTA', 'currency' => 'USD', 'amount' => '0.08'],
            ]]));
        });

        $quotes = $this->client($httpClient)->fetchPrices([Pair::BTC_USD, Pair::ETH_USD]);

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame(Exchange::Coinbase, $quotes['BTC_USD']?->exchange);
        self::assertSame(65100.25, $quotes['BTC_USD']->price);
        self::assertSame(3200.5, $quotes['ETH_USD']?->price);
    }

    public function testPairsMissingFromTheListAreFetchedIndividually(): void
    {
        $requested = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requested) {
            $requested[] = $url;

            return str_ends_with($url, '/v2/prices/USD/spot')
                ? new MockResponse((string) json_encode(['data' => [['base' => 'BTC', 'currency' => 'USD', 'amount' => '65100.25']]]))
                : new MockResponse((string) json_encode(['data' => ['base' => 'TRX', 'currency' => 'USD', 'amount' => '0.3375']]));
        });

        $quotes = $this->client($httpClient)->fetchPrices([Pair::BTC_USD, Pair::TRX_USD]);

        self::assertCount(2, $requested);
        self::assertStringEndsWith('/v2/prices/TRX-USD/spot', $requested[1]);
        self::assertSame(65100.25, $quotes['BTC_USD']?->price);
        self::assertSame(0.3375, $quotes['TRX_USD']?->price);
    }

    public function testAFailingIndividualRequestOnlyLosesThatPair(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url) => match (true) {
            str_ends_with($url, '/v2/prices/USD/spot') => new MockResponse((string) json_encode(['data' => []])),
            str_contains($url, 'TRX-USD') => new MockResponse('', ['http_code' => 404]),
            default => new MockResponse((string) json_encode(['data' => ['amount' => '1.5']])),
        });

        $quotes = $this->client($httpClient)->fetchPrices([Pair::TRX_USD, Pair::DOT_USD]);

        self::assertNull($quotes['TRX_USD']);
        self::assertSame(1.5, $quotes['DOT_USD']?->price);
    }

    public function testMalformedRowsAreSkipped(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url) => str_ends_with($url, '/v2/prices/USD/spot')
            ? new MockResponse((string) json_encode(['data' => [
                ['base' => 'BTC', 'currency' => 'EUR', 'amount' => '60000'],
                ['base' => 'ETH', 'currency' => 'USD', 'amount' => 'NaN'],
                'garbage',
            ]]))
            : new MockResponse((string) json_encode(['data' => 'nope'])));

        self::assertSame(['BTC_USD' => null, 'ETH_USD' => null], $this->client($httpClient)->fetchPrices([Pair::BTC_USD, Pair::ETH_USD]));
    }

    public function testNoIndividualRequestsWhileBackingOff(): void
    {
        $throttle = Upstream::throttle();
        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 429]));

        self::assertNull($this->client($httpClient, $throttle)->fetchPrice(Pair::BTC_USD));
        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertTrue($throttle->isBackingOff('coinbase'));
    }

    private function client(MockHttpClient $httpClient, ?UpstreamThrottle $throttle = null): CoinbaseClient
    {
        return new CoinbaseClient($httpClient, Upstream::unlimited(), $throttle ?? Upstream::throttle(), new PairSymbolMapper(), new NullLogger());
    }
}
