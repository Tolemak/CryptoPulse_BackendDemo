<?php

namespace App\Enum;

/**
 * Binance quotes in USDT, treated here as a 1:1 USD proxy — not a rigorous peg.
 */
enum Pair: string
{
    case BTC_USD = 'BTC_USD';
    case ETH_USD = 'ETH_USD';
    case XRP_USD = 'XRP_USD';
    case SOL_USD = 'SOL_USD';
    case ADA_USD = 'ADA_USD';
    case NEAR_USD = 'NEAR_USD';
    case SUI_USD = 'SUI_USD';
    case BNB_USD = 'BNB_USD';
    case DOGE_USD = 'DOGE_USD';
    case TRX_USD = 'TRX_USD';
    case DOT_USD = 'DOT_USD';
    case LINK_USD = 'LINK_USD';
    case LTC_USD = 'LTC_USD';
    case AVAX_USD = 'AVAX_USD';
    case ATOM_USD = 'ATOM_USD';
    case XLM_USD = 'XLM_USD';
    case UNI_USD = 'UNI_USD';
    case ETC_USD = 'ETC_USD';
    case FIL_USD = 'FIL_USD';
    case APT_USD = 'APT_USD';
    case ARB_USD = 'ARB_USD';
    case OP_USD = 'OP_USD';
    case ICP_USD = 'ICP_USD';
    case HBAR_USD = 'HBAR_USD';
    case VET_USD = 'VET_USD';
    case ALGO_USD = 'ALGO_USD';
    case SHIB_USD = 'SHIB_USD';
    case BCH_USD = 'BCH_USD';
    case AAVE_USD = 'AAVE_USD';
    case INJ_USD = 'INJ_USD';

    public static function fromRouteParam(string $value): self
    {
        return self::tryFrom(strtoupper($value))
            ?? throw new \App\Exception\PairNotFoundException($value);
    }
}
