<?php

namespace App\Service\Exchange;

use App\Dto\PriceQuote;
use App\Enum\Pair;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * One throttled upstream round trip for every pair; subclasses only know how
 * to ask their exchange and how to read its answer.
 */
abstract class BatchExchangeClient implements BulkFetchingExchangeClientInterface
{
    public function __construct(
        protected readonly HttpClientInterface $client,
        private readonly RateLimiterFactory $limiterFactory,
        private readonly UpstreamThrottle $throttle,
        protected readonly PairSymbolMapper $symbolMapper,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function fetchPrice(Pair $pair): ?PriceQuote
    {
        return $this->fetchPrices([$pair])[$pair->value] ?? null;
    }

    final public function fetchPrices(array $pairs): array
    {
        $quotes = array_fill_keys(array_map(static fn (Pair $pair) => $pair->value, $pairs), null);
        if ([] === $pairs || !$this->acquire()) {
            return $quotes;
        }

        try {
            $prices = $this->requestPrices(array_values($pairs));
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Exchange price fetch failed.', ['exchange' => $this->exchange()->value, 'error' => $e->getMessage()]);

            return $quotes;
        }

        $now = new \DateTimeImmutable();
        $missing = [];
        foreach ($pairs as $pair) {
            if (isset($prices[$pair->value])) {
                $quotes[$pair->value] = new PriceQuote($this->exchange(), $pair, $prices[$pair->value], $now);
            } else {
                $missing[] = $pair->value;
            }
        }

        if ([] !== $missing) {
            $this->logger->warning('Exchange returned no usable price.', ['exchange' => $this->exchange()->value, 'pairs' => $missing]);
        }

        return $quotes;
    }

    /**
     * @param non-empty-list<Pair> $pairs
     *
     * @return array<string, float> keyed by Pair::value, pairs without a usable price omitted
     *
     * @throws ExceptionInterface
     */
    abstract protected function requestPrices(array $pairs): array;

    protected function acquire(int $tokens = 1): bool
    {
        return $this->throttle->tryAcquire($this->exchange()->value, $this->limiterFactory, $tokens);
    }

    protected function backOff(): void
    {
        $this->throttle->backOff($this->exchange()->value);
    }

    /**
     * @return array<mixed> empty when the exchange is throttling us
     *
     * @throws ExceptionInterface
     */
    protected function decode(ResponseInterface $response): array
    {
        if ($this->throttle->backOffIfThrottled($this->exchange()->value, $response)) {
            return [];
        }

        return $response->toArray();
    }
}
