<?php

namespace App\Service\Exchange;

use App\Dto\AthInfo;
use App\Enum\Pair;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * All-time-high data has no source among the polled exchanges (they only
 * ever report the current spot price) - CoinGecko's /coins/markets endpoint
 * ships `ath`/`ath_date` per coin, and accepts a batch of ids in one call.
 */
final class CoinGeckoClient
{
    public function __construct(
        #[Target('coingeckoClient')]
        private readonly HttpClientInterface $client,
        #[Autowire(service: 'limiter.coingecko_api')]
        private readonly RateLimiterFactory $limiterFactory,
        private readonly CoinGeckoIdMapper $idMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Pair[] $pairs
     *
     * @return array<string, AthInfo> keyed by Pair::value, missing entries mean the fetch failed
     */
    public function fetchAthForAll(array $pairs): array
    {
        if ([] === $pairs) {
            return [];
        }

        if (!$this->limiterFactory->create()->consume()->isAccepted()) {
            $this->logger->warning('CoinGecko rate limit exhausted, skipping ATH refresh.');

            return [];
        }

        $idsByPair = [];
        foreach ($pairs as $pair) {
            $idsByPair[$this->idMapper->toCoinGeckoId($pair)] = $pair;
        }

        try {
            $response = $this->client->request('GET', '/api/v3/coins/markets', [
                'query' => [
                    'vs_currency' => 'usd',
                    'ids' => implode(',', array_keys($idsByPair)),
                    'per_page' => count($idsByPair),
                ],
            ]);
            $rows = $response->toArray();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('CoinGecko ATH fetch failed.', ['error' => $e->getMessage()]);

            return [];
        }

        $now = new \DateTimeImmutable();
        $result = [];
        foreach ($rows as $row) {
            $pair = $idsByPair[$row['id']] ?? null;
            if (null === $pair || !isset($row['ath'], $row['ath_date'])) {
                continue;
            }

            $result[$pair->value] = new AthInfo(
                $pair,
                (float) $row['ath'],
                new \DateTimeImmutable($row['ath_date']),
                $now,
            );
        }

        return $result;
    }
}
