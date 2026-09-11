<?php

namespace App\Tests\Command;

use App\Command\RefreshAthCommand;
use App\Service\Exchange\CoinGeckoClient;
use App\Service\Exchange\CoinGeckoIdMapper;
use App\Service\Price\AthCacheService;
use App\Service\Price\AthRefreshService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

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
        $limiterFactory = new RateLimiterFactory(
            ['id' => 'test_refresh_ath_cmd', 'policy' => 'token_bucket', 'limit' => 1000, 'rate' => ['interval' => '1 second', 'amount' => 1000]],
            new InMemoryStorage(),
        );

        $client = new CoinGeckoClient(
            new MockHttpClient(fn () => new MockResponse((string) json_encode($rows))),
            $limiterFactory,
            new CoinGeckoIdMapper(),
            new NullLogger(),
        );

        return new RefreshAthCommand(new AthRefreshService($client, new AthCacheService(new ArrayAdapter())));
    }
}
