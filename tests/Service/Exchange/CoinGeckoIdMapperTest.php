<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Pair;
use App\Service\Exchange\CoinGeckoIdMapper;
use PHPUnit\Framework\TestCase;

final class CoinGeckoIdMapperTest extends TestCase
{
    public function testEveryPairHasAMapping(): void
    {
        $mapper = new CoinGeckoIdMapper();

        foreach (Pair::cases() as $pair) {
            self::assertNotSame('', $mapper->toCoinGeckoId($pair), $pair->value.' has no CoinGecko id mapping');
        }
    }

    public function testRoundTripsBackToTheSamePair(): void
    {
        $mapper = new CoinGeckoIdMapper();

        foreach (Pair::cases() as $pair) {
            $id = $mapper->toCoinGeckoId($pair);
            self::assertSame($pair, $mapper->fromCoinGeckoId($id));
        }
    }

    public function testUnknownIdReturnsNull(): void
    {
        $mapper = new CoinGeckoIdMapper();

        self::assertNull($mapper->fromCoinGeckoId('not-a-real-coin'));
    }
}
