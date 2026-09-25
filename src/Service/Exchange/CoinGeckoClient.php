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
    private const string UPSTREAM = 'coingecko';

    public function __construct(
        #[Target('coingeckoClient')]
        private readonly HttpClientInterface $client,
        #[Autowire(service: 'limiter.coingecko_api')]
        private readonly RateLimiterFactory $limiterFactory,
        private readonly UpstreamThrottle $throttle,
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
        if ([] === $pairs || !$this->throttle->tryAcquire(self::UPSTREAM, $this->limiterFactory)) {
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
            if ($this->throttle->backOffIfThrottled(self::UPSTREAM, $response)) {
                return [];
            }
            $rows = $response->toArray();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('CoinGecko ATH fetch failed.', ['error' => $e->getMessage()]);

            return [];
        }

        $now = new \DateTimeImmutable();
        $result = [];
        foreach ($rows as $row) {
            $pair = is_array($row) && is_string($row['id'] ?? null) ? ($idsByPair[$row['id']] ?? null) : null;
            $ath = null === $pair ? null : self::toAthInfo($pair, $row, $now);
            if (null !== $ath) {
                $result[$pair->value] = $ath;
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $row
     */
    private static function toAthInfo(Pair $pair, array $row, \DateTimeImmutable $now): ?AthInfo
    {
        $athPrice = PriceValue::positive($row['ath'] ?? null);
        $athDate = is_string($row['ath_date'] ?? null) ? self::parseDate($row['ath_date']) : null;
        if (null === $athPrice || null === $athDate) {
            return null;
        }

        return new AthInfo($pair, $athPrice, $athDate, $now, PriceValue::positive($row['market_cap'] ?? null));
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
