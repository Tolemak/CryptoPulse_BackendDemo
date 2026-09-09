<?php

namespace App\Service\Exchange;

use App\Dto\PriceQuote;
use App\Enum\Exchange;
use App\Enum\Pair;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_client')]
interface ExchangeClientInterface
{
    public function exchange(): Exchange;

    /**
     * Never throws; returns null on any failure.
     */
    public function fetchPrice(Pair $pair): ?PriceQuote;
}
