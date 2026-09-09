<?php

namespace App\Enum;

enum Exchange: string
{
    case Binance = 'binance';
    case Kraken = 'kraken';
    case Coinbase = 'coinbase';
}
