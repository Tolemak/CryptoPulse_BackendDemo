<?php

namespace App\Service\Exchange;

use App\Enum\Exchange;

final class BinanceClient extends BatchExchangeClient
{
    public function exchange(): Exchange
    {
        return Exchange::Binance;
    }

    protected function requestPrices(array $pairs): array
    {
        $pairValuesBySymbol = [];
        foreach ($pairs as $pair) {
            $pairValuesBySymbol[$this->symbolMapper->toBinanceSymbol($pair)] = $pair->value;
        }

        $rows = $this->decode($this->client->request('GET', '/api/v3/ticker/price', [
            'query' => ['symbols' => json_encode(array_keys($pairValuesBySymbol), JSON_THROW_ON_ERROR)],
        ]));

        $prices = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['symbol'] ?? null)) {
                continue;
            }

            $pairValue = $pairValuesBySymbol[$row['symbol']] ?? null;
            $price = PriceValue::positive($row['price'] ?? null);
            if (null !== $pairValue && null !== $price) {
                $prices[$pairValue] = $price;
            }
        }

        return $prices;
    }
}
