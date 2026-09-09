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

final class CoinbaseClient implements ExchangeClientInterface
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
        if (!$this->limiterFactory->create()->consume()->isAccepted()) {
            $this->logger->warning('Coinbase rate limit exhausted, skipping poll.', ['pair' => $pair->value]);

            return null;
        }

        try {
            $symbol = $this->symbolMapper->toCoinbaseSymbol($pair);
            $response = $this->client->request('GET', "/v2/prices/{$symbol}/spot");
            $data = $response->toArray();

            return new PriceQuote($this->exchange(), $pair, (float) $data['data']['amount'], new \DateTimeImmutable());
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Coinbase price fetch failed.', ['pair' => $pair->value, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
