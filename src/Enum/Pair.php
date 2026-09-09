<?php

namespace App\Enum;

/**
 * Binance quotes in USDT, treated here as a 1:1 USD proxy — not a rigorous peg.
 */
enum Pair: string
{
    case BTC_USD = 'BTC_USD';
    case ETH_USD = 'ETH_USD';
    case SOL_USD = 'SOL_USD';

    public static function fromRouteParam(string $value): self
    {
        return self::tryFrom(strtoupper($value))
            ?? throw new \App\Exception\PairNotFoundException($value);
    }
}
