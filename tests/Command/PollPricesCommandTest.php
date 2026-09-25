<?php

namespace App\Tests\Command;

use App\Command\PollPricesCommand;
use App\Dto\AlertView;
use App\Enum\AlertCondition;
use App\Enum\Exchange;
use App\Enum\Pair;
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
        $tester = new CommandTester($this->command($this->createStub(AlertRepository::class), $this->createStub(WebhookNotifier::class)));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('BTC_USD: 101.00 (from 3 exchange(s))', $tester->getDisplay());
    }

    public function testItEvaluatesAlertsAgainstThePolledPrices(): void
    {
        $alert = new AlertView('a1', Pair::BTC_USD, AlertCondition::Above, 100.0, 'https://example.test/hook', new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'), false);
        $repo = $this->createStub(AlertRepository::class);
        $repo->method('findByPair')->willReturnCallback(fn (Pair $pair) => Pair::BTC_USD === $pair ? [$alert] : []);
        $repo->method('claimFiring')->willReturn(true);

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::once())->method('notifyAll')->willReturn(['a1' => true]);

        self::assertSame(0, (new CommandTester($this->command($repo, $notifier)))->execute([]));
    }

    private function command(AlertRepository $repo, WebhookNotifier $notifier): PollPricesCommand
    {
        $aggregator = new PriceAggregatorService([
            new StubExchangeClient(Exchange::Binance, 100.0),
            new StubExchangeClient(Exchange::Kraken, 102.0),
            new StubExchangeClient(Exchange::Coinbase, 101.0),
        ]);

        $refresh = new PriceRefreshService(
            $aggregator,
            new PriceCacheService(new ArrayAdapter()),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        return new PollPricesCommand($refresh, new AlertEvaluatorService($repo, $notifier, new NullLogger()));
    }
}
