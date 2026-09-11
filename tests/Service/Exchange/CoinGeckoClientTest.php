<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Pair;
use App\Service\Exchange\CoinGeckoClient;
use App\Service\Exchange\CoinGeckoIdMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

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

    public function testFetchAthForAllGivesUpWhenRateLimited(): void
    {
        $httpClient = new MockHttpClient(fn () => self::fail('The rate limiter should block the request.'));
        $exhausted = new RateLimiterFactory(
            ['id' => 'test_coingecko_blocked', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $exhausted->create()->consume();

        $client = new CoinGeckoClient($httpClient, $exhausted, new CoinGeckoIdMapper(), new NullLogger());

        self::assertSame([], $client->fetchAthForAll([Pair::BTC_USD]));
    }

    private function client(MockHttpClient $httpClient): CoinGeckoClient
    {
        $limiterFactory = new RateLimiterFactory(
            ['id' => 'test_coingecko', 'policy' => 'token_bucket', 'limit' => 1000, 'rate' => ['interval' => '1 second', 'amount' => 1000]],
            new InMemoryStorage(),
        );

        return new CoinGeckoClient($httpClient, $limiterFactory, new CoinGeckoIdMapper(), new NullLogger());
    }
}
