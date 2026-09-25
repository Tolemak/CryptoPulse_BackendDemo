<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Pair;
use App\Service\Exchange\CoinGeckoClient;
use App\Service\Exchange\CoinGeckoIdMapper;
use App\Service\Exchange\UpstreamThrottle;
use App\Tests\Support\Upstream;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class CoinGeckoClientTest extends TestCase
{
    public function testFetchAthForAllParsesResponseKeyedByPair(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertSame('GET', $method);
            self::assertStringContainsString('/api/v3/coins/markets', $url);
            self::assertStringContainsString('bitcoin', $url);

            return new MockResponse((string) json_encode([
                ['id' => 'bitcoin', 'ath' => 126080.0, 'ath_date' => '2025-10-06T10:57:42.000Z', 'market_cap' => 1_500_000_000_000],
                ['id' => 'ethereum', 'ath' => 4946.05, 'ath_date' => '2025-08-24T00:00:00.000Z', 'market_cap' => 300_000_000_000],
            ]));
        });

        $result = $this->client($httpClient)->fetchAthForAll([Pair::BTC_USD, Pair::ETH_USD]);

        self::assertArrayHasKey('BTC_USD', $result);
        self::assertArrayHasKey('ETH_USD', $result);
        self::assertSame(126080.0, $result['BTC_USD']->athPrice);
        self::assertSame(1_500_000_000_000.0, $result['BTC_USD']->marketCap);
        self::assertSame('2025-10-06', $result['BTC_USD']->athDate->format('Y-m-d'));
    }

    public function testFetchAthForAllSkipsRowsWithoutAthData(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse((string) json_encode([
            ['id' => 'bitcoin', 'ath' => 126080.0, 'ath_date' => '2025-10-06T10:57:42.000Z', 'market_cap' => 1000],
            ['id' => 'ethereum', 'market_cap' => 500],
        ])));

        $result = $this->client($httpClient)->fetchAthForAll([Pair::BTC_USD, Pair::ETH_USD]);

        self::assertArrayHasKey('BTC_USD', $result);
        self::assertArrayNotHasKey('ETH_USD', $result);
    }

    public function testFetchAthForAllSkipsMalformedRows(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse((string) json_encode([
            ['id' => 'bitcoin', 'ath' => 'lots', 'ath_date' => '2025-10-06T10:57:42.000Z'],
            ['id' => 'ethereum', 'ath' => 4946.05, 'ath_date' => 'not a date'],
            ['id' => 'solana', 'ath' => 293.31, 'ath_date' => ['2025-01-19']],
            ['id' => ['ripple'], 'ath' => 3.65, 'ath_date' => '2025-07-18T00:00:00.000Z'],
            'garbage',
            ['id' => 'cardano', 'ath' => 3.09, 'ath_date' => '2021-09-02T06:00:10.474Z', 'market_cap' => 'unknown'],
        ])));

        $result = $this->client($httpClient)->fetchAthForAll([Pair::BTC_USD, Pair::ETH_USD, Pair::SOL_USD, Pair::XRP_USD, Pair::ADA_USD]);

        self::assertSame(['ADA_USD'], array_keys($result));
        self::assertNull($result['ADA_USD']->marketCap);
    }

    public function testFetchAthForAllLeavesMarketCapNullWhenAbsent(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse((string) json_encode([
            ['id' => 'bitcoin', 'ath' => 100.0, 'ath_date' => '2025-01-01T00:00:00.000Z'],
        ])));

        $result = $this->client($httpClient)->fetchAthForAll([Pair::BTC_USD]);

        self::assertNull($result['BTC_USD']->marketCap);
    }

    public function testFetchAthForAllReturnsEmptyArrayForNoPairs(): void
    {
        $httpClient = new MockHttpClient(fn () => self::fail('No request should be made for an empty pair list.'));

        self::assertSame([], $this->client($httpClient)->fetchAthForAll([]));
    }

    public function testFetchAthForAllReturnsEmptyArrayOnTransportFailure(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 502]));

        self::assertSame([], $this->client($httpClient)->fetchAthForAll([Pair::BTC_USD]));
    }

    public function testFetchAthForAllReturnsEmptyArrayForANonJsonBody(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('<html>maintenance</html>'));

        self::assertSame([], $this->client($httpClient)->fetchAthForAll([Pair::BTC_USD]));
    }

    public function testFetchAthForAllGivesUpWhenRateLimited(): void
    {
        $httpClient = new MockHttpClient(fn () => self::fail('The rate limiter should block the request.'));

        self::assertSame([], $this->client($httpClient, Upstream::exhausted())->fetchAthForAll([Pair::BTC_USD]));
    }

    public function testATooManyRequestsAnswerStartsABackOff(): void
    {
        $throttle = Upstream::throttle();
        $httpClient = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 429]));

        self::assertSame([], $this->client($httpClient, throttle: $throttle)->fetchAthForAll([Pair::BTC_USD]));
        self::assertTrue($throttle->isBackingOff('coingecko'));
    }

    private function client(MockHttpClient $httpClient, ?RateLimiterFactory $limiter = null, ?UpstreamThrottle $throttle = null): CoinGeckoClient
    {
        return new CoinGeckoClient($httpClient, $limiter ?? Upstream::unlimited(), $throttle ?? Upstream::throttle(), new CoinGeckoIdMapper(), new NullLogger());
    }
}
