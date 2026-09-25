<?php

namespace App\Dto;

final readonly class AlertTrigger
{
    public function __construct(
        public AlertView $alert,
        public float $price,
    ) {
    }
}
