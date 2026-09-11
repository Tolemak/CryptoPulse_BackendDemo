<?php

namespace App\Tests\Service\Price;

use App\Enum\Pair;
use App\Service\Exchange\CoinGeckoClient;
use App\Service\Exchange\CoinGeckoIdMapper;
use App\Service\Price\AthCacheService;
use App\Service\Price\AthRefreshService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AthRefreshServiceTest extends TestCase
{
    public function testRefreshAllWritesEveryReturnedAthToTheCache(): void
    {
        $cache = new AthCacheService(new ArrayAdapter());
        $service = new AthRefreshService($this->coinGeckoReturning([
            ['id' => 'bitcoin', 'ath' => 126080.0, 'ath_date' => '2025-10-06T10:57:42.000Z', 'market_cap' => 1000],
            ['id' => 'ethereum', 'ath' => 4946.05, 'ath_date' => '2025-08-24T00:00:00.000Z', 'market_cap' => 500],
        ]), $cache);

        $refreshed = $service->refreshAll();

        self::assertCount(2, $refreshed);
        self::assertSame(126080.0, $cache->read(Pair::BTC_USD)?->athPrice);
        self::assertSame(4946.05, $cache->read(Pair::ETH_USD)?->athPrice);
    }

    public function testRefreshAllLeavesCacheUntouchedWhenTheApiFails(): void
    {
        $cache = new AthCacheService(new ArrayAdapter());
        $httpClient = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 500]));
        $service = new AthRefreshService($this->coinGeckoWith($httpClient), $cache);

        self::assertSame([], $service->refreshAll());
        self::assertNull($cache->read(Pair::BTC_USD));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function coinGeckoReturning(array $rows): CoinGeckoClient
    {
        return $this->coinGeckoWith(new MockHttpClient(
            fn () => new MockResponse((string) json_encode($rows)),
        ));
    }

    private function coinGeckoWith(MockHttpClient $httpClient): CoinGeckoClient
    {
        $limiterFactory = new RateLimiterFactory(
            ['id' => 'test_ath_refresh', 'policy' => 'token_bucket', 'limit' => 1000, 'rate' => ['interval' => '1 second', 'amount' => 1000]],
            new InMemoryStorage(),
        );

        return new CoinGeckoClient($httpClient, $limiterFactory, new CoinGeckoIdMapper(), new NullLogger());
    }
}
