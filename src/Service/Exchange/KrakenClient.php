<?php

namespace App\Service\Exchange;

use App\Enum\Exchange;

final class KrakenClient extends BatchExchangeClient
{
    private const array THROTTLE_ERRORS = ['EAPI:Rate limit exceeded', 'EGeneral:Too many requests', 'EService:Throttled'];

    public function exchange(): Exchange
    {
        return Exchange::Kraken;
    }

    protected function requestPrices(array $pairs): array
    {
        $symbolsByPairValue = [];
        foreach ($pairs as $pair) {
            $symbolsByPairValue[$pair->value] = $this->symbolMapper->toKrakenSymbol($pair);
        }

        $data = $this->decode($this->client->request('GET', '/0/public/Ticker', [
            'query' => ['pair' => implode(',', $symbolsByPairValue)],
        ]));

        $errors = $data['error'] ?? [];
        if (!is_array($errors) || [] !== $errors) {
            $this->logger->warning('Kraken returned an error.', ['error' => $errors]);
            if (is_array($errors) && [] !== array_intersect(self::THROTTLE_ERRORS, $errors)) {
                $this->backOff();
            }

            return [];
        }

        $result = $data['result'] ?? null;
        if (!is_array($result)) {
            return [];
        }

        $prices = [];
        foreach ($symbolsByPairValue as $pairValue => $symbol) {
            $ticker = $result[$symbol] ?? $result[self::legacyResultKey($symbol)] ?? null;
            $lastTrade = is_array($ticker) ? ($ticker['c'] ?? null) : null;
            $price = is_array($lastTrade) ? PriceValue::positive($lastTrade[0] ?? null) : null;
            if (null !== $price) {
                $prices[$pairValue] = $price;
            }
        }

        return $prices;
    }

    /**
     * Assets with a legacy code come back under their full name, e.g.
     * XBTUSD -> XXBTZUSD, ETHUSD -> XETHZUSD.
     */
    private static function legacyResultKey(string $symbol): string
    {
        return 'X'.substr($symbol, 0, -3).'Z'.substr($symbol, -3);
    }
}
