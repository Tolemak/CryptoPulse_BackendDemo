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
            $prices = [];
            foreach (Pair::cases() as $pair) {
                $price = $this->aggregator->aggregate($pair);

                if ($price === null) {
                    $this->logger->warning('No exchange returned a price, skipping.', ['pair' => $pair->value]);
                    continue;
                }

                $this->cache->write($price);
                $this->evaluator->evaluate($price);
                $prices[] = $price;
            }

            return $prices;
        } finally {
            $lock->release();
        }
    }
}
