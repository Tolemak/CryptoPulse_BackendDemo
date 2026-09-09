<?php

namespace App\Dto;

use App\Enum\Exchange;
use App\Enum\Pair;

final readonly class PriceQuote
{
    public function __construct(
        public Exchange $exchange,
        public Pair $pair,
        public float $price,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }
}
