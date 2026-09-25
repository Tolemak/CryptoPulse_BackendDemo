<?php

namespace App\Service\Exchange;

final class PriceValue
{
    public static function positive(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number > 0 ? $number : null;
    }
}
