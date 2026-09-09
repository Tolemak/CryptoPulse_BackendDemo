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

final class KrakenClient implements ExchangeClientInterface
{
    public function __construct(
        #[Target('krakenClient')]
        private readonly HttpClientInterface $client,
        #[Autowire(service: 'limiter.kraken_api')]
        private readonly RateLimiterFactory $limiterFactory,
        private readonly PairSymbolMapper $symbolMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function exchange(): Exchange
    {
        return Exchange::Kraken;
    }

    public function fetchPrice(Pair $pair): ?PriceQuote
    {
        if (!$this->limiterFactory->create()->consume()->isAccepted()) {
            $this->logger->warning('Kraken rate limit exhausted, skipping poll.', ['pair' => $pair->value]);

            return null;
        }

        try {
            $response = $this->client->request('GET', '/0/public/Ticker', [
                'query' => ['pair' => $this->symbolMapper->toKrakenSymbol($pair)],
            ]);
            $data = $response->toArray();

            if (!empty($data['error'])) {
                $this->logger->warning('Kraken returned an error.', ['pair' => $pair->value, 'error' => $data['error']]);

                return null;
            }

            // Kraken renames the pair in the result key (e.g. XBTUSD -> XXBTZUSD).
            // Querying a single pair always yields exactly one result entry, so
            // take it positionally instead of relying on the exact key name.
            $result = reset($data['result']);
            if ($result === false) {
                return null;
            }

            // "c" = last trade closed [price, lot volume].
            return new PriceQuote($this->exchange(), $pair, (float) $result['c'][0], new \DateTimeImmutable());
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Kraken price fetch failed.', ['pair' => $pair->value, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
