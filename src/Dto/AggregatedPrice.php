<?php

namespace App\Dto;

use App\Enum\Pair;

final readonly class AggregatedPrice
{
    /**
     * @param PriceQuote[] $breakdown per-exchange quotes that made up this aggregate
     */
    public function __construct(
        public Pair $pair,
        public float $median,
        public array $breakdown,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
