<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Exchange\BinanceClient;
use App\Service\Exchange\PairSymbolMapper;
use App\Service\Exchange\UpstreamThrottle;
use App\Tests\Support\Upstream;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class BinanceClientTest extends TestCase
{
    public function testFetchPricesAsksForEveryPairInOneRequest(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertSame('GET', $method);
            self::assertStringContainsString('symbols=["BTCUSDT","ETHUSDT"]', urldecode($url));

            return new MockResponse((string) json_encode([
                ['symbol' => 'ETHUSDT', 'price' => '3200.50'],
                ['symbol' => 'BTCUSDT', 'price' => '65432.10'],
            ]));
        });

        $quotes = $this->client($httpClient)->fetchPrices([Pair::BTC_USD, Pair::ETH_USD]);

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame(Exchange::Binance, $quotes['BTC_USD']?->exchange);
        self::assertSame(65432.10, $quotes['BTC_USD']->price);
        self::assertSame(3200.50, $quotes['ETH_USD']?->price);
    }

    public function testFetchPriceDelegatesToTheBatch(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([['symbol' => 'BTCUSDT', 'price' => '65432.10']])));

        self::assertSame(65432.10, $this->client($httpClient)->fetchPrice(Pair::BTC_USD)?->price);
    }

    public function testMalformedRowsAreSkipped(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([
            ['symbol' => 'BTCUSDT', 'price' => 'not-a-number'],
            ['symbol' => 'ETHUSDT', 'price' => '-1'],
            ['symbol' => 'SOLUSDT'],
            'garbage',
            ['symbol' => 'XRPUSDT', 'price' => '0.52'],
        ])));

        $quotes = $this->client($httpClient)->fetchPrices([Pair::BTC_USD, Pair::ETH_USD, Pair::SOL_USD, Pair::XRP_USD]);

        self::assertNull($quotes['BTC_USD']);
        self::assertNull($quotes['ETH_USD']);
        self::assertNull($quotes['SOL_USD']);
        self::assertSame(0.52, $quotes['XRP_USD']?->price);
    }

    public function testUnexpectedTopLevelShapeYieldsNoQuotes(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode(['symbol' => 'BTCUSDT', 'price' => '1'])));

        self::assertSame(['BTC_USD' => null], $this->client($httpClient)->fetchPrices([Pair::BTC_USD]));
    }

    public function testTransportFailureYieldsNoQuotes(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        self::assertNull($this->client($httpClient)->fetchPrice(Pair::BTC_USD));
    }

    public function testRateLimitedResponseStartsABackOff(): void
    {
        $throttle = Upstream::throttle();
        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '120']]));

        self::assertNull($this->client($httpClient, throttle: $throttle)->fetchPrice(Pair::BTC_USD));
        self::assertTrue($throttle->isBackingOff('binance'));
    }

    public function testNoRequestIsMadeWhileBackingOff(): void
    {
        $throttle = Upstream::throttle();
        $throttle->backOff('binance');
        $httpClient = new MockHttpClient(fn () => self::fail('No request should be made while backing off.'));

        self::assertSame(['BTC_USD' => null], $this->client($httpClient, throttle: $throttle)->fetchPrices([Pair::BTC_USD]));
    }

    public function testNoRequestIsMadeOnceTheLocalLimitIsSpent(): void
    {
        $httpClient = new MockHttpClient(fn () => self::fail('The rate limiter should block the request.'));

        self::assertNull($this->client($httpClient, Upstream::exhausted())->fetchPrice(Pair::BTC_USD));
    }

    public function testEmptyPairListMakesNoRequest(): void
    {
        $httpClient = new MockHttpClient(fn () => self::fail('No request should be made for an empty pair list.'));

        self::assertSame([], $this->client($httpClient)->fetchPrices([]));
    }

    private function client(MockHttpClient $httpClient, ?RateLimiterFactory $limiter = null, ?UpstreamThrottle $throttle = null): BinanceClient
    {
        return new BinanceClient($httpClient, $limiter ?? Upstream::unlimited(), $throttle ?? Upstream::throttle(), new PairSymbolMapper(), new NullLogger());
    }
}
