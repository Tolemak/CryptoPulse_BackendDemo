<?php

namespace App\Tests\Service\Exchange;

use App\Enum\Pair;
use App\Service\Exchange\PairSymbolMapper;
use PHPUnit\Framework\TestCase;

final class PairSymbolMapperTest extends TestCase
{
    private const array KRAKEN_LEGACY_CODES = ['BTC' => 'XBT', 'DOGE' => 'XDG'];

    public function testEveryBinanceSymbolIsTheBaseAssetQuotedInUsdt(): void
    {
        $mapper = new PairSymbolMapper();

        foreach (Pair::cases() as $pair) {
            self::assertSame($this->base($pair).'USDT', $mapper->toBinanceSymbol($pair));
        }
    }

    public function testEveryKrakenSymbolIsTheBaseAssetQuotedInUsdWithLegacyCodesForBtcAndDoge(): void
    {
        $mapper = new PairSymbolMapper();

        foreach (Pair::cases() as $pair) {
            $base = $this->base($pair);

            self::assertSame((self::KRAKEN_LEGACY_CODES[$base] ?? $base).'USD', $mapper->toKrakenSymbol($pair));
        }
    }

    public function testCoinbaseSymbolIsDerivedFromThePairValue(): void
    {
        $mapper = new PairSymbolMapper();

        self::assertSame('BTC-USD', $mapper->toCoinbaseSymbol(Pair::BTC_USD));
        self::assertSame('NEAR-USD', $mapper->toCoinbaseSymbol(Pair::NEAR_USD));
    }

    private function base(Pair $pair): string
    {
        return substr($pair->value, 0, -strlen('_USD'));
    }
}
