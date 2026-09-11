<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Exchange\KrakenClient;
use App\Service\Exchange\PairSymbolMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class KrakenClientTest extends TestCase
{
    private function unlimitedLimiterFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_kraken', 'policy' => 'token_bucket', 'limit' => 1000, 'rate' => ['interval' => '1 second', 'amount' => 1000]],
            new InMemoryStorage(),
        );
    }

    public function testFetchPriceHandlesKrakensRenamedResultKey(): void
    {
        // Kraken renames the queried pair (XBTUSD) to XXBTZUSD in the result key —
        // the client must not assume the key matches what it queried.
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertStringContainsString('pair=XBTUSD', $url);

            return new MockResponse((string) json_encode([
                'error' => [],
                'result' => ['XXBTZUSD' => ['c' => ['65000.50', '0.001']]],
            ]));
        });

        $client = new KrakenClient($httpClient, $this->unlimitedLimiterFactory(), new PairSymbolMapper(), new NullLogger());
        $quote = $client->fetchPrice(Pair::BTC_USD);

        self::assertNotNull($quote);
        self::assertSame(Exchange::Kraken, $quote->exchange);
        self::assertSame(65000.50, $quote->price);
    }

    public function testFetchPriceReturnsNullOnKrakenError(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse((string) json_encode([
            'error' => ['EQuery:Unknown asset pair'],
            'result' => [],
        ])));

        $client = new KrakenClient($httpClient, $this->unlimitedLimiterFactory(), new PairSymbolMapper(), new NullLogger());

        self::assertNull($client->fetchPrice(Pair::BTC_USD));
    }
}
