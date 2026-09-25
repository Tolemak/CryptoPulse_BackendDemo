<?php

namespace App\Tests\Command;

use App\Command\RefreshAthCommand;
use App\Service\Exchange\CoinGeckoClient;
use App\Service\Exchange\CoinGeckoIdMapper;
use App\Service\Price\AthCacheService;
use App\Service\Price\AthRefreshService;
use App\Tests\Support\Upstream;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RefreshAthCommandTest extends TestCase
{
    public function testItPrintsEveryRefreshedPair(): void
    {
        $tester = new CommandTester($this->command([
            ['id' => 'bitcoin', 'ath' => 126080.0, 'ath_date' => '2025-10-06T10:57:42.000Z', 'market_cap' => 1000],
        ]));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('BTC_USD: ATH 126080.00 (2025-10-06)', $tester->getDisplay());
    }

    public function testItSucceedsQuietlyWhenNothingWasRefreshed(): void
    {
        $tester = new CommandTester($this->command([]));

        self::assertSame(0, $tester->execute([]));
        self::assertStringNotContainsString('ATH', $tester->getDisplay());
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function command(array $rows): RefreshAthCommand
    {
        $client = new CoinGeckoClient(
            new MockHttpClient(fn () => new MockResponse((string) json_encode($rows))),
            Upstream::unlimited(),
            Upstream::throttle(),
            new CoinGeckoIdMapper(),
            new NullLogger(),
        );

        return new RefreshAthCommand(new AthRefreshService($client, new AthCacheService(new ArrayAdapter())));
    }
}
