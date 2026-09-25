<?php

namespace App\Tests\Service\Exchange;

use App\Tests\Support\Upstream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class UpstreamThrottleTest extends TestCase
{
    public function testAcquiresWhileTokensRemain(): void
    {
        self::assertTrue(Upstream::throttle()->tryAcquire('binance', Upstream::unlimited()));
    }

    public function testRefusesOnceTheLocalLimitIsSpent(): void
    {
        self::assertFalse(Upstream::throttle()->tryAcquire('binance', Upstream::exhausted()));
    }

    public function testRefusesMoreTokensThanTheBucketCanEverHold(): void
    {
        $small = new RateLimiterFactory(
            ['id' => 'test_small', 'policy' => 'token_bucket', 'limit' => 2, 'rate' => ['interval' => '1 second']],
            new InMemoryStorage(),
        );

        self::assertFalse(Upstream::throttle()->tryAcquire('coinbase', $small, 3));
    }

    public function testRefusesWhileBackingOffWithoutSpendingATokenAndOnlyForThatUpstream(): void
    {
        $throttle = Upstream::throttle();
        $throttle->backOff('kraken');

        self::assertFalse($throttle->tryAcquire('kraken', Upstream::exhausted()));
        self::assertTrue($throttle->tryAcquire('binance', Upstream::unlimited()));
    }

    public function testBackOffWindowIsAtLeastOneSecond(): void
    {
        $cache = new ArrayAdapter();
        Upstream::throttle($cache)->backOff('kraken', 0);

        self::assertEqualsWithDelta(time() + 1, $this->expiryOf($cache, 'upstream.backoff.kraken'), 1);
    }

    /**
     * @return iterable<string, array{int, array<string, string>, bool}>
     */
    public static function responseProvider(): iterable
    {
        yield 'ok' => [200, [], false];
        yield 'server error' => [503, [], false];
        yield 'too many requests' => [429, [], true];
        yield 'binance ip ban' => [418, ['Retry-After' => '300'], true];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('responseProvider')]
    public function testBacksOffOnlyOnThrottlingAnswers(int $status, array $headers, bool $expected): void
    {
        $throttle = Upstream::throttle();

        self::assertSame($expected, $throttle->backOffIfThrottled('binance', $this->response($status, $headers)));
        self::assertSame($expected, $throttle->isBackingOff('binance'));
    }

    public function testRetryAfterSetsTheWindowAndIsCapped(): void
    {
        $cache = new ArrayAdapter();
        $throttle = Upstream::throttle($cache);

        $throttle->backOffIfThrottled('binance', $this->response(429, ['Retry-After' => '999999']));

        $expiry = $this->expiryOf($cache, 'upstream.backoff.binance');
        self::assertEqualsWithDelta(time() + 3600, $expiry, 2);
    }

    public function testNonNumericRetryAfterFallsBackToTheDefault(): void
    {
        $cache = new ArrayAdapter();
        $throttle = Upstream::throttle($cache);

        $throttle->backOffIfThrottled('binance', $this->response(429, ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']));

        self::assertEqualsWithDelta(time() + 60, $this->expiryOf($cache, 'upstream.backoff.binance'), 2);
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(int $status, array $headers): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return (new MockHttpClient(new MockResponse('', ['http_code' => $status, 'response_headers' => $headers])))->request('GET', 'https://example.test');
    }

    private function expiryOf(ArrayAdapter $cache, string $key): float
    {
        $item = $cache->getItem($key);
        self::assertTrue($item->isHit());
        $expiry = $item->getMetadata()['expiry'] ?? null;
        self::assertIsNumeric($expiry);

        return (float) $expiry;
    }
}
