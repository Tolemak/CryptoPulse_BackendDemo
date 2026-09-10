<?php

namespace App\Service\Exchange;

use App\Dto\PriceQuote;
use App\Enum\Pair;

/**
 * Opt-in extension for clients whose HTTP client can dispatch requests
 * concurrently (Symfony's HttpClient does this naturally when you collect
 * all the Response objects before reading any of them) - lets the
 * aggregator fetch every pair from this exchange in one round-trip's worth
 * of wall-clock time instead of one request-then-wait per pair.
 */
interface BulkFetchingExchangeClientInterface extends ExchangeClientInterface
{
    /**
     * @param Pair[] $pairs
     *
     * @return array<string, PriceQuote|null> keyed by Pair::value, never throws
     */
    public function fetchPrices(array $pairs): array;
}
