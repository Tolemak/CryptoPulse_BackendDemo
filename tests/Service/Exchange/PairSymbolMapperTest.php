<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Pair;
use App\Service\Exchange\PairSymbolMapper;
use PHPUnit\Framework\TestCase;

final class PairSymbolMapperTest extends TestCase
{
    /**
     * The Binance/Kraken lookup arrays are hand-maintained per pair (unlike
     * Coinbase, which is derived programmatically) - a missing entry is a
     * silent "Undefined array key" fatal at poll time rather than a
     * compile-time error, so every new Pair case needs this covered.
     */
    public function testEveryPairHasABinanceAndKrakenSymbol(): void
    {
        $mapper = new PairSymbolMapper();

        foreach (Pair::cases() as $pair) {
            self::assertNotSame('', $mapper->toBinanceSymbol($pair), $pair->value.' has no Binance symbol mapping');
            self::assertNotSame('', $mapper->toKrakenSymbol($pair), $pair->value.' has no Kraken symbol mapping');
        }
    }

    public function testCoinbaseSymbolIsDerivedFromThePairValue(): void
    {
        $mapper = new PairSymbolMapper();

        self::assertSame('BTC-USD', $mapper->toCoinbaseSymbol(Pair::BTC_USD));
    }
}
