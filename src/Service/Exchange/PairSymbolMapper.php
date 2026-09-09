<?php

namespace App\Service\Exchange;

use App\Enum\Pair;

final class PairSymbolMapper
{
    private const array BINANCE_SYMBOLS = [
        'BTC_USD' => 'BTCUSDT',
        'ETH_USD' => 'ETHUSDT',
        'SOL_USD' => 'SOLUSDT',
    ];

    private const array KRAKEN_SYMBOLS = [
        'BTC_USD' => 'XBTUSD',
        'ETH_USD' => 'ETHUSD',
        'SOL_USD' => 'SOLUSD',
    ];

    public function toBinanceSymbol(Pair $pair): string
    {
        return self::BINANCE_SYMBOLS[$pair->value];
    }

    public function toKrakenSymbol(Pair $pair): string
    {
        return self::KRAKEN_SYMBOLS[$pair->value];
    }

    public function toCoinbaseSymbol(Pair $pair): string
    {
        return str_replace('_', '-', $pair->value);
    }
}
