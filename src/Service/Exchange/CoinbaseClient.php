<?php

namespace App\Service\Exchange;

use App\Dto\PriceQuote;
use App\Enum\Exchange;
use App\Enum\Pair;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CoinbaseClient implements BulkFetchingExchangeClientInterface
{
    public function __construct(
        #[Target('coinbaseClient')]
        private readonly HttpClientInterface $client,
        #[Autowire(service: 'limiter.coinbase_api')]
        private readonly RateLimiterFactory $limiterFactory,
        private readonly PairSymbolMapper $symbolMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function exchange(): Exchange
    {
        return Exchange::Coinbase;
    }

    public function fetchPrice(Pair $pair): ?PriceQuote
    {
        return $this->fetchPrices([$pair])[$pair->value] ?? null;
    }

    /**
     * Coinbase has no batch spot-price endpoint, so scaling to many pairs
     * means one HTTP call per pair - fired concurrently here instead of
     * sequentially, since Symfony's HttpClient dispatches requests as soon
     * as they're created and only blocks once a response is actually read.
     *
     * @param Pair[] $pairs
     *
     * @return array<string, PriceQuote|null>
     */
    public function fetchPrices(array $pairs): array
    {
        $responses = [];
        foreach ($pairs as $pair) {
            if (!$this->limiterFactory->create()->consume()->isAccepted()) {
                $this->logger->warning('Coinbase rate limit exhausted, skipping poll.', ['pair' => $pair->value]);
                continue;
            }

            $symbol = $this->symbolMapper->toCoinbaseSymbol($pair);
            $responses[$pair->value] = $this->client->request('GET', "/v2/prices/{$symbol}/spot");
        }

        $quotes = [];
        foreach ($responses as $pairValue => $response) {
            try {
                $data = $response->toArray();
                $quotes[$pairValue] = new PriceQuote(
                    $this->exchange(),
                    Pair::from($pairValue),
                    (float) $data['data']['amount'],
                    new \DateTimeImmutable(),
                );
            } catch (ExceptionInterface $e) {
                $this->logger->warning('Coinbase price fetch failed.', ['pair' => $pairValue, 'error' => $e->getMessage()]);
                $quotes[$pairValue] = null;
            }
        }

        return $quotes;
    }
}
