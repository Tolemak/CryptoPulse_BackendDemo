<?php

namespace App\Service\Exchange;

use App\Enum\Exchange;
use App\Enum\Pair;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * The USD spot list covers almost every pair in one call; the few it leaves
 * out are asked for one by one, concurrently.
 */
final class CoinbaseClient extends BatchExchangeClient
{
    public function exchange(): Exchange
    {
        return Exchange::Coinbase;
    }

    protected function requestPrices(array $pairs): array
    {
        $listed = $this->spotPricesByBase($this->decode($this->client->request('GET', '/v2/prices/USD/spot'))['data'] ?? null);

        $prices = [];
        $missing = [];
        foreach ($pairs as $pair) {
            $price = $listed[$this->base($pair)] ?? null;
            if (null !== $price) {
                $prices[$pair->value] = $price;
            } else {
                $missing[] = $pair;
            }
        }

        if ([] === $missing || !$this->acquire(count($missing))) {
            return $prices;
        }

        return $prices + $this->fetchIndividually($missing);
    }

    /**
     * @param list<Pair> $pairs
     *
     * @return array<string, float>
     */
    private function fetchIndividually(array $pairs): array
    {
        $responses = [];
        foreach ($pairs as $pair) {
            $responses[$pair->value] = $this->client->request('GET', "/v2/prices/{$this->symbolMapper->toCoinbaseSymbol($pair)}/spot");
        }

        $prices = [];
        foreach ($responses as $pairValue => $response) {
            try {
                $data = $this->decode($response)['data'] ?? null;
            } catch (ExceptionInterface $e) {
                $this->logger->warning('Coinbase price fetch failed.', ['pair' => $pairValue, 'error' => $e->getMessage()]);
                continue;
            }

            $price = is_array($data) ? PriceValue::positive($data['amount'] ?? null) : null;
            if (null !== $price) {
                $prices[$pairValue] = $price;
            }
        }

        return $prices;
    }

    /**
     * @return array<string, float>
     */
    private function spotPricesByBase(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['base'] ?? null) || 'USD' !== ($row['currency'] ?? null)) {
                continue;
            }

            $price = PriceValue::positive($row['amount'] ?? null);
            if (null !== $price) {
                $prices[$row['base']] ??= $price;
            }
        }

        return $prices;
    }

    private function base(Pair $pair): string
    {
        return explode('-', $this->symbolMapper->toCoinbaseSymbol($pair))[0];
    }
}
