<?php

namespace App\Service\Price;

use App\Dto\AggregatedPrice;
use App\Enum\Pair;
use App\Service\Alert\AlertEvaluatorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

final class PriceRefreshService
{
    public function __construct(
        private readonly PriceAggregatorService $aggregator,
        private readonly PriceCacheService $cache,
        private readonly AlertEvaluatorService $evaluator,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return AggregatedPrice[]
     */
    public function refreshAll(): array
    {
        $lock = $this->lockFactory->createLock('app:poll-prices', ttl: 300.0, autoRelease: true);
        if (!$lock->acquire()) {
            $this->logger->info('Refresh already in progress, returning current cache instead.');

            return array_values(array_filter(array_map(
                fn (Pair $pair) => $this->cache->read($pair),
                Pair::cases(),
            )));
        }

        try {
            $prices = $this->aggregator->aggregateAll(Pair::cases());
            $pairsWithAPrice = array_map(static fn ($price) => $price->pair, $prices);

            foreach (Pair::cases() as $pair) {
                if (!in_array($pair, $pairsWithAPrice, true)) {
                    $this->logger->warning('No exchange returned a price, skipping.', ['pair' => $pair->value]);
                }
            }

            foreach ($prices as $price) {
                $this->cache->write($price);
                $this->evaluator->evaluate($price);
            }

            return $prices;
        } finally {
            $lock->release();
        }
    }
}
