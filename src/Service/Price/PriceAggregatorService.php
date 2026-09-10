<?php

namespace App\Service\Price;

use App\Dto\AggregatedPrice;
use App\Enum\Pair;
use App\Service\Exchange\BulkFetchingExchangeClientInterface;
use App\Service\Exchange\ExchangeClientInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PriceAggregatorService
{
    /**
     * @param iterable<ExchangeClientInterface> $clients
     */
    public function __construct(
        #[AutowireIterator('app.exchange_client')]
        private readonly iterable $clients,
    ) {
    }

    /**
     * @return AggregatedPrice|null null only if every exchange failed
     */
    public function aggregate(Pair $pair): ?AggregatedPrice
    {
        $results = $this->aggregateAll([$pair]);

        return $results[0] ?? null;
    }

    /**
     * Fetches every pair from every exchange client, batching per client
     * where supported (see BulkFetchingExchangeClientInterface) so scaling
     * the pair list doesn't turn into a chain of sequential HTTP round-trips.
     *
     * @param Pair[] $pairs
     *
     * @return AggregatedPrice[] one per pair that got at least one quote, in no particular order
     */
    public function aggregateAll(array $pairs): array
    {
        $quotesByPair = array_fill_keys(array_map(static fn (Pair $p) => $p->value, $pairs), []);

        foreach ($this->clients as $client) {
            if ($client instanceof BulkFetchingExchangeClientInterface) {
                foreach ($client->fetchPrices($pairs) as $pairValue => $quote) {
                    if (null !== $quote) {
                        $quotesByPair[$pairValue][] = $quote;
                    }
                }
                continue;
            }

            foreach ($pairs as $pair) {
                $quote = $client->fetchPrice($pair);
                if (null !== $quote) {
                    $quotesByPair[$pair->value][] = $quote;
                }
            }
        }

        $results = [];
        foreach ($pairs as $pair) {
            $quotes = $quotesByPair[$pair->value];
            if ([] === $quotes) {
                continue;
            }

            $results[] = new AggregatedPrice($pair, self::median($quotes), $quotes, new \DateTimeImmutable());
        }

        return $results;
    }

    /**
     * @param \App\Dto\PriceQuote[] $quotes
     */
    private static function median(array $quotes): float
    {
        $prices = array_map(static fn ($q) => $q->price, $quotes);
        sort($prices);
        $count = count($prices);
        $middle = intdiv($count, 2);

        return 0 === $count % 2
            ? ($prices[$middle - 1] + $prices[$middle]) / 2
            : $prices[$middle];
    }
}
