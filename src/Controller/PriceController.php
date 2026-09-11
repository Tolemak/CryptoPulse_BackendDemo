<?php

namespace App\Controller;

use App\Dto\AggregatedPrice;
use App\Dto\AthInfo;
use App\Enum\Pair;
use App\Service\Price\AthCacheService;
use App\Service\Price\PriceCacheService;
use App\Service\Price\PriceRefreshService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/prices')]
final class PriceController extends AbstractController
{
    public function __construct(
        private readonly PriceCacheService $cache,
        private readonly AthCacheService $athCache,
        private readonly PriceRefreshService $refresh,
        #[Autowire(service: 'limiter.manual_refresh')]
        private readonly RateLimiterFactory $refreshLimiter,
    ) {
    }

    #[Route('', name: 'prices_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $pairs = Pair::cases();
        $pricesByPair = $this->cache->readMany($pairs);
        $athByPair = $this->athCache->readMany($pairs);

        $prices = [];
        foreach ($pairs as $pair) {
            $price = $pricesByPair[$pair->value] ?? null;
            if ($price !== null) {
                $prices[] = $this->withAth($price, $athByPair[$pair->value] ?? null);
            }
        }

        return $this->json($this->sortByMarketCap($prices));
    }

    #[Route('/{pair}', name: 'prices_show', methods: ['GET'])]
    public function show(string $pair): JsonResponse
    {
        $price = $this->cache->read(Pair::fromRouteParam($pair));

        if ($price === null) {
            return $this->json(['error' => 'No price data yet for this pair. Try again shortly.'], 404);
        }

        return $this->json($this->withAth($price, $this->athCache->read($price->pair)));
    }

    /**
     * Rate-limited globally (not per-IP): one real re-poll per 60s, shared
     * across every caller.
     */
    #[Route('/refresh', name: 'prices_refresh', methods: ['POST'])]
    public function refresh(): JsonResponse
    {
        $limit = $this->refreshLimiter->create()->consume();

        if (!$limit->isAccepted()) {
            $response = $this->json(['error' => 'A refresh was already requested recently. Try again shortly.'], 429);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        $refreshed = $this->refresh->refreshAll();
        $athByPair = $this->athCache->readMany(array_map(fn (AggregatedPrice $price) => $price->pair, $refreshed));

        $prices = array_map(
            fn (AggregatedPrice $price) => $this->withAth($price, $athByPair[$price->pair->value] ?? null),
            $refreshed,
        );

        return $this->json($this->sortByMarketCap($prices));
    }

    /**
     * ATH is refreshed on its own daily schedule (app:refresh-ath) - here we
     * just decorate the current price with whatever ATH is cached.
     *
     * @return array<string, mixed>
     */
    private function withAth(AggregatedPrice $price, ?AthInfo $ath): array
    {
        return [
            'pair' => $price->pair,
            'median' => $price->median,
            'breakdown' => $price->breakdown,
            'updatedAt' => $price->updatedAt,
            'athPrice' => $ath?->athPrice,
            'athDate' => $ath?->athDate,
            'pctFromAth' => $this->pctFromAth($price, $ath),
            'marketCap' => $ath?->marketCap,
        ];
    }

    private function pctFromAth(AggregatedPrice $price, ?AthInfo $ath): ?float
    {
        if (null === $ath || $ath->athPrice <= 0) {
            return null;
        }

        return round((($price->median - $ath->athPrice) / $ath->athPrice) * 100, 2);
    }

    /**
     * Pairs with no cached market cap yet (ATH not refreshed) sort last rather
     * than dropping out, so a fresh deploy still lists every polled pair.
     *
     * @param array<int, array<string, mixed>> $prices
     *
     * @return array<int, array<string, mixed>>
     */
    private function sortByMarketCap(array $prices): array
    {
        usort($prices, fn (array $a, array $b) => ($b['marketCap'] ?? -1) <=> ($a['marketCap'] ?? -1));

        return $prices;
    }
}
