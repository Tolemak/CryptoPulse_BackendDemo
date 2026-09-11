<?php

namespace App\Tests\Controller;

use App\Dto\AggregatedPrice;
use App\Dto\AthInfo;
use App\Dto\PriceQuote;
use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Price\AthCacheService;
use App\Service\Price\PriceCacheService;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class PriceControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        (new Client($_ENV['REDIS_URL']))->flushdb();
    }

    public function testListReturnsOnlyPairsWithCachedData(): void
    {
        $client = static::createClient();

        $this->priceCache()->write(new AggregatedPrice(
            Pair::BTC_USD,
            65000.0,
            [new PriceQuote(Exchange::Binance, Pair::BTC_USD, 65000.0, new \DateTimeImmutable())],
            new \DateTimeImmutable(),
        ));

        $client->request('GET', '/api/prices');

        self::assertResponseIsSuccessful();
        $data = self::jsonBody($client);
        self::assertCount(1, $data);
        self::assertSame('BTC_USD', $data[0]['pair']);
        self::assertEquals(65000.0, $data[0]['median']);
    }

    public function testShowReturns404WhenNoDataCachedYet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/prices/BTC_USD');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowReturns400ForUnknownPair(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/prices/NOT_A_PAIR');

        self::assertResponseStatusCodeSame(400);
    }

    public function testShowReturnsCachedAggregate(): void
    {
        $client = static::createClient();

        $this->priceCache()->write(new AggregatedPrice(
            Pair::ETH_USD,
            3200.5,
            [
                new PriceQuote(Exchange::Binance, Pair::ETH_USD, 3200.0, new \DateTimeImmutable()),
                new PriceQuote(Exchange::Kraken, Pair::ETH_USD, 3201.0, new \DateTimeImmutable()),
            ],
            new \DateTimeImmutable(),
        ));

        $client->request('GET', '/api/prices/ETH_USD');

        self::assertResponseIsSuccessful();
        $data = self::jsonBody($client);
        self::assertSame(3200.5, $data['median']);
        self::assertCount(2, $data['breakdown']);
    }

    public function testShowIncludesAthFieldsWhenCached(): void
    {
        $client = static::createClient();

        $this->priceCache()->write(new AggregatedPrice(
            Pair::BTC_USD,
            65000.0,
            [new PriceQuote(Exchange::Binance, Pair::BTC_USD, 65000.0, new \DateTimeImmutable())],
            new \DateTimeImmutable(),
        ));
        $this->athCache()->write(new AthInfo(
            Pair::BTC_USD,
            100000.0,
            new \DateTimeImmutable('2025-01-20'),
            new \DateTimeImmutable(),
            1_500_000_000_000.0,
        ));

        $client->request('GET', '/api/prices/BTC_USD');

        self::assertResponseIsSuccessful();
        $data = self::jsonBody($client);
        self::assertEquals(100000.0, $data['athPrice']);
        self::assertEquals(-35.0, $data['pctFromAth']);
        self::assertEquals(1_500_000_000_000.0, $data['marketCap']);
    }

    public function testListSortsByMarketCapDescendingWithUnknownsLast(): void
    {
        $client = static::createClient();
        $priceCache = $this->priceCache();
        $athCache = $this->athCache();

        foreach ([Pair::BTC_USD, Pair::ETH_USD, Pair::SOL_USD] as $pair) {
            $priceCache->write(new AggregatedPrice(
                $pair,
                100.0,
                [new PriceQuote(Exchange::Binance, $pair, 100.0, new \DateTimeImmutable())],
                new \DateTimeImmutable(),
            ));
        }

        // BTC has the smaller cap here on purpose, to prove sort order isn't coincidentally alphabetical/enum order.
        $athCache->write(new AthInfo(Pair::BTC_USD, 100000.0, new \DateTimeImmutable(), new \DateTimeImmutable(), 500.0));
        $athCache->write(new AthInfo(Pair::ETH_USD, 5000.0, new \DateTimeImmutable(), new \DateTimeImmutable(), 2000.0));
        // SOL has no ATH/market-cap cached yet.

        $client->request('GET', '/api/prices');

        self::assertResponseIsSuccessful();
        $data = self::jsonBody($client);
        self::assertSame(['ETH_USD', 'BTC_USD', 'SOL_USD'], array_column($data, 'pair'));
    }

    public function testShowOmitsAthFieldsWhenNotCachedYet(): void
    {
        $client = static::createClient();

        $this->priceCache()->write(new AggregatedPrice(
            Pair::SOL_USD,
            150.0,
            [new PriceQuote(Exchange::Binance, Pair::SOL_USD, 150.0, new \DateTimeImmutable())],
            new \DateTimeImmutable(),
        ));

        $client->request('GET', '/api/prices/SOL_USD');

        self::assertResponseIsSuccessful();
        $data = self::jsonBody($client);
        self::assertNull($data['athPrice']);
        self::assertNull($data['pctFromAth']);
    }

    public function testRefreshReturns429WhenLimiterAlreadyConsumed(): void
    {
        $client = static::createClient();

        $limiter = static::getContainer()->get('limiter.manual_refresh');
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        $limiter->create()->consume();

        $client->request('POST', '/api/prices/refresh');

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        $data = self::jsonBody($client);
        self::assertArrayHasKey('error', $data);
    }

    private function priceCache(): PriceCacheService
    {
        $service = static::getContainer()->get(PriceCacheService::class);
        self::assertInstanceOf(PriceCacheService::class, $service);

        return $service;
    }

    private function athCache(): AthCacheService
    {
        $service = static::getContainer()->get(AthCacheService::class);
        self::assertInstanceOf(AthCacheService::class, $service);

        return $service;
    }

    /**
     * @return array<mixed>
     */
    private static function jsonBody(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
