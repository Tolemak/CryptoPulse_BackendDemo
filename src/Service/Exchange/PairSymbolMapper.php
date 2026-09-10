<?php

namespace App\Service\Exchange;

use App\Enum\Pair;

final class PairSymbolMapper
{
    private const array BINANCE_SYMBOLS = [
        'BTC_USD' => 'BTCUSDT',
        'ETH_USD' => 'ETHUSDT',
        'XRP_USD' => 'XRPUSDT',
        'SOL_USD' => 'SOLUSDT',
        'ADA_USD' => 'ADAUSDT',
        'NEAR_USD' => 'NEARUSDT',
        'SUI_USD' => 'SUIUSDT',
        'BNB_USD' => 'BNBUSDT',
        'DOGE_USD' => 'DOGEUSDT',
        'TRX_USD' => 'TRXUSDT',
        'DOT_USD' => 'DOTUSDT',
        'LINK_USD' => 'LINKUSDT',
        'LTC_USD' => 'LTCUSDT',
        'AVAX_USD' => 'AVAXUSDT',
        'ATOM_USD' => 'ATOMUSDT',
        'XLM_USD' => 'XLMUSDT',
        'UNI_USD' => 'UNIUSDT',
        'ETC_USD' => 'ETCUSDT',
        'FIL_USD' => 'FILUSDT',
        'APT_USD' => 'APTUSDT',
        'ARB_USD' => 'ARBUSDT',
        'OP_USD' => 'OPUSDT',
        'ICP_USD' => 'ICPUSDT',
        'HBAR_USD' => 'HBARUSDT',
        'VET_USD' => 'VETUSDT',
        'ALGO_USD' => 'ALGOUSDT',
        'SHIB_USD' => 'SHIBUSDT',
        'BCH_USD' => 'BCHUSDT',
        'AAVE_USD' => 'AAVEUSDT',
        'INJ_USD' => 'INJUSDT',
    ];

    // Kraken keeps legacy asset codes for a couple of pairs (BTC -> XBT,
    // DOGE -> XDG); everything else is PLAIN_SYMBOL + USD.
    private const array KRAKEN_SYMBOLS = [
        'BTC_USD' => 'XBTUSD',
        'ETH_USD' => 'ETHUSD',
        'XRP_USD' => 'XRPUSD',
        'SOL_USD' => 'SOLUSD',
        'ADA_USD' => 'ADAUSD',
        'NEAR_USD' => 'NEARUSD',
        'SUI_USD' => 'SUIUSD',
        'BNB_USD' => 'BNBUSD',
        'DOGE_USD' => 'XDGUSD',
        'TRX_USD' => 'TRXUSD',
        'DOT_USD' => 'DOTUSD',
        'LINK_USD' => 'LINKUSD',
        'LTC_USD' => 'LTCUSD',
        'AVAX_USD' => 'AVAXUSD',
        'ATOM_USD' => 'ATOMUSD',
        'XLM_USD' => 'XLMUSD',
        'UNI_USD' => 'UNIUSD',
        'ETC_USD' => 'ETCUSD',
        'FIL_USD' => 'FILUSD',
        'APT_USD' => 'APTUSD',
        'ARB_USD' => 'ARBUSD',
        'OP_USD' => 'OPUSD',
        'ICP_USD' => 'ICPUSD',
        'HBAR_USD' => 'HBARUSD',
        'VET_USD' => 'VETUSD',
        'ALGO_USD' => 'ALGOUSD',
        'SHIB_USD' => 'SHIBUSD',
        'BCH_USD' => 'BCHUSD',
        'AAVE_USD' => 'AAVEUSD',
        'INJ_USD' => 'INJUSD',
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
