<?php

namespace App\Tests\Service\Price;

use App\Dto\AggregatedPrice;
use App\Dto\PriceQuote;
use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Alert\AlertEvaluatorService;
use App\Service\Alert\AlertRepository;
use App\Service\Alert\WebhookNotifier;
use App\Service\Price\PriceAggregatorService;
use App\Service\Price\PriceCacheService;
use App\Service\Price\PriceRefreshService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class PriceRefreshServiceTest extends TestCase
{
    public function testRefreshAllCachesEveryAggregatedPrice(): void
    {
        $cache = new PriceCacheService(new ArrayAdapter());
        $service = $this->service($cache, new LockFactory(new InMemoryStore()));

        $prices = $service->refreshAll();

        self::assertCount(count(Pair::cases()), $prices);
        self::assertSame(101.0, $cache->read(Pair::BTC_USD)?->median);
    }

    public function testRefreshAllFallsBackToTheCacheWhileAnotherRunHoldsTheLock(): void
    {
        $cache = new PriceCacheService(new ArrayAdapter());
        $cache->write(new AggregatedPrice(
            Pair::BTC_USD,
            42.0,
            [new PriceQuote(Exchange::Binance, Pair::BTC_USD, 42.0, new \DateTimeImmutable())],
            new \DateTimeImmutable(),
        ));

        $lockFactory = new LockFactory(new InMemoryStore());
        $lockFactory->createLock('app:poll-prices', ttl: 300.0, autoRelease: false)->acquire();

        $prices = $this->service($cache, $lockFactory)->refreshAll();

        self::assertCount(1, $prices);
        self::assertSame(42.0, $prices[0]->median);
    }

    private function service(PriceCacheService $cache, LockFactory $lockFactory): PriceRefreshService
    {
        $aggregator = new PriceAggregatorService([
            new StubExchangeClient(Exchange::Binance, 100.0),
            new StubExchangeClient(Exchange::Kraken, 102.0),
            new StubExchangeClient(Exchange::Coinbase, 101.0),
        ]);

        $evaluator = new AlertEvaluatorService(
            $this->createStub(AlertRepository::class),
            $this->createStub(WebhookNotifier::class),
            new NullLogger(),
        );

        return new PriceRefreshService($aggregator, $cache, $evaluator, $lockFactory, new NullLogger());
    }
}
