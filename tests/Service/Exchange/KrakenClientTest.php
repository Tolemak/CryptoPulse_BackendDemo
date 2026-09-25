<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Exchange\KrakenClient;
use App\Service\Exchange\PairSymbolMapper;
use App\Service\Exchange\UpstreamThrottle;
use App\Tests\Support\Upstream;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KrakenClientTest extends TestCase
{
    public function testFetchPricesMatchesRenamedAndPlainResultKeys(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertStringContainsString('pair='.rawurlencode('XBTUSD,XDGUSD,SOLUSD'), $url);

            return new MockResponse((string) json_encode([
                'error' => [],
                'result' => [
                    'SOLUSD' => ['c' => ['116.38', '1.0']],
                    'XDGUSD' => ['c' => ['0.0951', '100']],
                    'XXBTZUSD' => ['c' => ['65000.50', '0.001']],
                ],
            ]));
        });

        $quotes = $this->client($httpClient)->fetchPrices([Pair::BTC_USD, Pair::DOGE_USD, Pair::SOL_USD]);

        self::assertSame(1, $httpClient->getRequestsCount());
        self::assertSame(Exchange::Kraken, $quotes['BTC_USD']?->exchange);
        self::assertSame(65000.50, $quotes['BTC_USD']->price);
        self::assertSame(0.0951, $quotes['DOGE_USD']?->price);
        self::assertSame(116.38, $quotes['SOL_USD']?->price);
    }

    public function testKrakenErrorYieldsNoQuotes(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([
            'error' => ['EQuery:Unknown asset pair'],
            'result' => [],
        ])));
        $throttle = Upstream::throttle();

        self::assertNull($this->client($httpClient, $throttle)->fetchPrice(Pair::BTC_USD));
        self::assertFalse($throttle->isBackingOff('kraken'));
    }

    public function testRateLimitErrorStartsABackOff(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode(['error' => ['EAPI:Rate limit exceeded']])));
        $throttle = Upstream::throttle();

        self::assertNull($this->client($httpClient, $throttle)->fetchPrice(Pair::BTC_USD));
        self::assertTrue($throttle->isBackingOff('kraken'));
    }

    public function testMalformedTickersAreSkipped(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([
            'error' => [],
            'result' => [
                'XXBTZUSD' => ['c' => 'oops'],
                'XETHZUSD' => ['a' => ['1', '1']],
                'SOLUSD' => ['c' => [null]],
                'ADAUSD' => ['c' => ['0.25', '10']],
            ],
        ])));

        $quotes = $this->client($httpClient)->fetchPrices([Pair::BTC_USD, Pair::ETH_USD, Pair::SOL_USD, Pair::ADA_USD]);

        self::assertNull($quotes['BTC_USD']);
        self::assertNull($quotes['ETH_USD']);
        self::assertNull($quotes['SOL_USD']);
        self::assertSame(0.25, $quotes['ADA_USD']?->price);
    }

    public function testMissingResultYieldsNoQuotes(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode(['error' => [], 'result' => 'nope'])));

        self::assertNull($this->client($httpClient)->fetchPrice(Pair::BTC_USD));
    }

    public function testNonArrayErrorFieldIsTreatedAsAnError(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([
            'error' => 'something broke',
            'result' => ['XXBTZUSD' => ['c' => ['65000.50', '0.001']]],
        ])));

        self::assertNull($this->client($httpClient)->fetchPrice(Pair::BTC_USD));
    }

    private function client(MockHttpClient $httpClient, ?UpstreamThrottle $throttle = null): KrakenClient
    {
        return new KrakenClient($httpClient, Upstream::unlimited(), $throttle ?? Upstream::throttle(), new PairSymbolMapper(), new NullLogger());
    }
}
