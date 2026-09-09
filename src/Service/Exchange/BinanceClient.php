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

final class BinanceClient implements ExchangeClientInterface
{
    public function __construct(
        #[Target('binanceClient')]
        private readonly HttpClientInterface $client,
        #[Autowire(service: 'limiter.binance_api')]
        private readonly RateLimiterFactory $limiterFactory,
        private readonly PairSymbolMapper $symbolMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function exchange(): Exchange
    {
        return Exchange::Binance;
    }

    public function fetchPrice(Pair $pair): ?PriceQuote
    {
        if (!$this->limiterFactory->create()->consume()->isAccepted()) {
            $this->logger->warning('Binance rate limit exhausted, skipping poll.', ['pair' => $pair->value]);

            return null;
        }

        try {
            $response = $this->client->request('GET', '/api/v3/ticker/price', [
                'query' => ['symbol' => $this->symbolMapper->toBinanceSymbol($pair)],
            ]);
            $data = $response->toArray();

            return new PriceQuote($this->exchange(), $pair, (float) $data['price'], new \DateTimeImmutable());
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Binance price fetch failed.', ['pair' => $pair->value, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
