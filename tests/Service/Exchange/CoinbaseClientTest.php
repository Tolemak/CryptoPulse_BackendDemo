<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Exchange\CoinbaseClient;
use App\Service\Exchange\PairSymbolMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class CoinbaseClientTest extends TestCase
{
    private function unlimitedLimiterFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_coinbase', 'policy' => 'token_bucket', 'limit' => 1000, 'rate' => ['interval' => '1 second', 'amount' => 1000]],
            new InMemoryStorage(),
        );
    }

    public function testFetchPriceParsesCoinbaseResponse(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertStringContainsString('/v2/prices/BTC-USD/spot', $url);

            return new MockResponse(json_encode([
                'data' => ['base' => 'BTC', 'currency' => 'USD', 'amount' => '65100.25'],
            ]));
        });

        $client = new CoinbaseClient($httpClient, $this->unlimitedLimiterFactory(), new PairSymbolMapper(), new NullLogger());
        $quote = $client->fetchPrice(Pair::BTC_USD);

        self::assertNotNull($quote);
        self::assertSame(Exchange::Coinbase, $quote->exchange);
        self::assertSame(65100.25, $quote->price);
    }
}
