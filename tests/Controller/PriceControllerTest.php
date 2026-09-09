<?php

namespace App\Tests\Controller;

use App\Dto\AggregatedPrice;
use App\Dto\PriceQuote;
use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Price\PriceCacheService;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PriceControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        (new Client($_ENV['REDIS_URL']))->flushdb();
    }

    public function testListReturnsOnlyPairsWithCachedData(): void
    {
        $client = static::createClient();
        $cache = static::getContainer()->get(PriceCacheService::class);

        $cache->write(new AggregatedPrice(
            Pair::BTC_USD,
            65000.0,
            [new PriceQuote(Exchange::Binance, Pair::BTC_USD, 65000.0, new \DateTimeImmutable())],
            new \DateTimeImmutable(),
        ));

        $client->request('GET', '/api/prices');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
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
        $cache = static::getContainer()->get(PriceCacheService::class);

        $cache->write(new AggregatedPrice(
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
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(3200.5, $data['median']);
        self::assertCount(2, $data['breakdown']);
    }

    public function testRefreshReturns429WhenLimiterAlreadyConsumed(): void
    {
        $client = static::createClient();
        static::getContainer()->get('limiter.manual_refresh')->create()->consume();

        $client->request('POST', '/api/prices/refresh');

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }
}
