<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Pair;
use App\Service\Exchange\BinanceClient;
use App\Service\Exchange\CoinbaseClient;
use App\Service\Exchange\KrakenClient;
use App\Service\Exchange\PairSymbolMapper;
use App\Service\Exchange\UpstreamThrottle;
use App\Tests\Support\IsolatedRedis;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The configured limiters must let a full poll of every pair through - and a
 * manual refresh right after the scheduled one - or pairs silently go missing.
 */
final class ExchangeRateLimitConfigTest extends KernelTestCase
{
    use IsolatedRedis;

    private const int CONSECUTIVE_POLLS = 2;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testBinanceLimiterCoversEveryPair(): void
    {
        $mapper = new PairSymbolMapper();
        $rows = array_map(fn (Pair $pair) => ['symbol' => $mapper->toBinanceSymbol($pair), 'price' => '1.0'], Pair::cases());
        $client = new BinanceClient($this->respondingWith(['' => $rows]), $this->limiter('binance_api'), $this->throttle(), $mapper, new NullLogger());

        $this->assertEveryPollQuotesEveryPair(fn () => $client->fetchPrices(Pair::cases()));
    }

    public function testKrakenLimiterCoversEveryPair(): void
    {
        $mapper = new PairSymbolMapper();
        $result = [];
        foreach (Pair::cases() as $pair) {
            $result[$mapper->toKrakenSymbol($pair)] = ['c' => ['1.0', '1']];
        }
        $client = new KrakenClient($this->respondingWith(['' => ['error' => [], 'result' => $result]]), $this->limiter('kraken_api'), $this->throttle(), $mapper, new NullLogger());

        $this->assertEveryPollQuotesEveryPair(fn () => $client->fetchPrices(Pair::cases()));
    }

    public function testCoinbaseLimiterCoversEveryPairEvenWhenTheSpotListIsEmpty(): void
    {
        $client = new CoinbaseClient(
            $this->respondingWith(['/v2/prices/USD/spot' => ['data' => []], '' => ['data' => ['amount' => '1.0']]]),
            $this->limiter('coinbase_api'),
            $this->throttle(),
            new PairSymbolMapper(),
            new NullLogger(),
        );

        $this->assertEveryPollQuotesEveryPair(fn () => $client->fetchPrices(Pair::cases()));
    }

    /**
     * @param callable(): array<string, mixed> $poll
     */
    private function assertEveryPollQuotesEveryPair(callable $poll): void
    {
        for ($i = 1; $i <= self::CONSECUTIVE_POLLS; ++$i) {
            $quotes = $poll();
            self::assertCount(count(Pair::cases()), array_filter($quotes), "Poll {$i} lost pairs to the rate limiter.");
        }
    }

    /**
     * @param array<string, mixed> $bodiesByUrlSuffix '' is the fallback
     */
    private function respondingWith(array $bodiesByUrlSuffix): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($bodiesByUrlSuffix) {
            foreach ($bodiesByUrlSuffix as $suffix => $body) {
                if ('' !== $suffix && str_ends_with($url, $suffix)) {
                    return new MockResponse((string) json_encode($body));
                }
            }

            return new MockResponse((string) json_encode($bodiesByUrlSuffix['']));
        });
    }

    private function limiter(string $name): RateLimiterFactory
    {
        $limiter = self::getContainer()->get('limiter.'.$name);
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);

        return $limiter;
    }

    private function throttle(): UpstreamThrottle
    {
        $throttle = self::getContainer()->get(UpstreamThrottle::class);
        self::assertInstanceOf(UpstreamThrottle::class, $throttle);

        return $throttle;
    }
}
