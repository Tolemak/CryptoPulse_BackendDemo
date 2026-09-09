<?php

namespace App\Dto;

use App\Enum\AlertCondition;
use App\Enum\Pair;

final readonly class AlertView
{
    public function __construct(
        public string $id,
        public Pair $pair,
        public AlertCondition $condition,
        public float $threshold,
        public string $webhookUrl,
        public \DateTimeImmutable $createdAt,
        public bool $fired,
    ) {
    }
}
