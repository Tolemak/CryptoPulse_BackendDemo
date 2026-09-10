<?php

namespace App\Service\Exchange;

use App\Enum\Pair;

final class CoinGeckoIdMapper
{
    private const array COINGECKO_IDS = [
        'BTC_USD' => 'bitcoin',
        'ETH_USD' => 'ethereum',
        'XRP_USD' => 'ripple',
        'SOL_USD' => 'solana',
        'ADA_USD' => 'cardano',
        'NEAR_USD' => 'near',
        'SUI_USD' => 'sui',
        'BNB_USD' => 'binancecoin',
        'DOGE_USD' => 'dogecoin',
        'TRX_USD' => 'tron',
        'DOT_USD' => 'polkadot',
        'LINK_USD' => 'chainlink',
        'LTC_USD' => 'litecoin',
        'AVAX_USD' => 'avalanche-2',
        'ATOM_USD' => 'cosmos',
        'XLM_USD' => 'stellar',
        'UNI_USD' => 'uniswap',
        'ETC_USD' => 'ethereum-classic',
        'FIL_USD' => 'filecoin',
        'APT_USD' => 'aptos',
        'ARB_USD' => 'arbitrum',
        'OP_USD' => 'optimism',
        'ICP_USD' => 'internet-computer',
        'HBAR_USD' => 'hedera-hashgraph',
        'VET_USD' => 'vechain',
        'ALGO_USD' => 'algorand',
        'SHIB_USD' => 'shiba-inu',
        'BCH_USD' => 'bitcoin-cash',
        'AAVE_USD' => 'aave',
        'INJ_USD' => 'injective-protocol',
    ];

    public function toCoinGeckoId(Pair $pair): string
    {
        return self::COINGECKO_IDS[$pair->value];
    }

    public function fromCoinGeckoId(string $id): ?Pair
    {
        $pairValue = array_search($id, self::COINGECKO_IDS, true);

        return false === $pairValue ? null : Pair::from($pairValue);
    }
}
