<?php

namespace App\Service\Price;

use App\Dto\AggregatedPrice;
use App\Enum\Pair;
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
        $quotes = [];
        foreach ($this->clients as $client) {
            $quote = $client->fetchPrice($pair);
            if ($quote !== null) {
                $quotes[] = $quote;
            }
        }

        if ($quotes === []) {
            return null;
        }

        return new AggregatedPrice($pair, self::median($quotes), $quotes, new \DateTimeImmutable());
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
