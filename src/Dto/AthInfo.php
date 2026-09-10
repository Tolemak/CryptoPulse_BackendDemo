<?php

namespace App\Dto;

use App\Enum\Pair;

final readonly class AthInfo
{
    public function __construct(
        public Pair $pair,
        public float $athPrice,
        public \DateTimeImmutable $athDate,
        public \DateTimeImmutable $updatedAt,
        public ?float $marketCap = null,
    ) {
    }
}
