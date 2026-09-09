<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Exchange;
use App\Enum\Pair;
use App\Service\Exchange\BinanceClient;
use App\Service\Exchange\PairSymbolMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class BinanceClientTest extends TestCase
{
    private function unlimitedLimiterFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_binance', 'policy' => 'token_bucket', 'limit' => 1000, 'rate' => ['interval' => '1 second', 'amount' => 1000]],
            new InMemoryStorage(),
        );
    }

    public function testFetchPriceParsesBinanceResponse(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertSame('GET', $method);
            self::assertStringContainsString('symbol=BTCUSDT', $url);

            return new MockResponse(json_encode(['symbol' => 'BTCUSDT', 'price' => '65432.10']));
        });

        $client = new BinanceClient($httpClient, $this->unlimitedLimiterFactory(), new PairSymbolMapper(), new NullLogger());
        $quote = $client->fetchPrice(Pair::BTC_USD);

        self::assertNotNull($quote);
        self::assertSame(Exchange::Binance, $quote->exchange);
        self::assertSame(Pair::BTC_USD, $quote->pair);
        self::assertSame(65432.10, $quote->price);
    }

    public function testFetchPriceReturnsNullOnTransportFailure(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 500]));

        $client = new BinanceClient($httpClient, $this->unlimitedLimiterFactory(), new PairSymbolMapper(), new NullLogger());

        self::assertNull($client->fetchPrice(Pair::BTC_USD));
    }
}
