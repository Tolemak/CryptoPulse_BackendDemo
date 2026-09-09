<?php

namespace App\Enum;

enum AlertCondition: string
{
    case Above = 'above';
    case Below = 'below';

    public function isCrossedBy(float $threshold, float $price): bool
    {
        return match ($this) {
            self::Above => $price > $threshold,
            self::Below => $price < $threshold,
        };
    }
}
