<?php

namespace App\Service\Price;

use App\Dto\AthInfo;
use App\Enum\Pair;
use App\Service\Exchange\CoinGeckoClient;

final class AthRefreshService
{
    public function __construct(
        private readonly CoinGeckoClient $client,
        private readonly AthCacheService $cache,
    ) {
    }

    /**
     * @return AthInfo[]
     */
    public function refreshAll(): array
    {
        $athByPair = $this->client->fetchAthForAll(Pair::cases());

        foreach ($athByPair as $ath) {
            $this->cache->write($ath);
        }

        return array_values($athByPair);
    }
}
