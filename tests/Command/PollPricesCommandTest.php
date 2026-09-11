<?php

namespace App\Tests\Command;

use App\Command\PollPricesCommand;
use App\Enum\Exchange;
use App\Service\Alert\AlertEvaluatorService;
use App\Service\Alert\AlertRepository;
use App\Service\Alert\WebhookNotifier;
use App\Service\Price\PriceAggregatorService;
use App\Service\Price\PriceCacheService;
use App\Service\Price\PriceRefreshService;
use App\Tests\Service\Price\StubExchangeClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class PollPricesCommandTest extends TestCase
{
    public function testItPrintsTheMedianAndExchangeCountPerPair(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('BTC_USD: 101.00 (from 3 exchange(s))', $tester->getDisplay());
    }

    private function command(): PollPricesCommand
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

        $refresh = new PriceRefreshService(
            $aggregator,
            new PriceCacheService(new ArrayAdapter()),
            $evaluator,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        return new PollPricesCommand($refresh);
    }
}
