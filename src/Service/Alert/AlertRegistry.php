<?php

namespace App\Service\Alert;

use App\Dto\AlertView;
use App\Enum\AlertCondition;
use App\Enum\Pair;
use App\Exception\AlertCapacityReachedException;
use Symfony\Component\Uid\Uuid;

/**
 * Alerts are created anonymously, so storage is bounded twice: every alert
 * expires, and there is a hard cap on how many can be active at once.
 */
final class AlertRegistry
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly int $maxActive = 500,
        private readonly int $ttlDays = 30,
    ) {
    }

    public function register(Pair $pair, AlertCondition $condition, float $threshold, string $webhookUrl): AlertView
    {
        $now = new \DateTimeImmutable();
        if ($this->alerts->countActive($now) >= $this->maxActive) {
            throw new AlertCapacityReachedException();
        }

        $id = Uuid::v7()->toRfc4122();
        $this->alerts->save($id, $pair, $condition, $threshold, $webhookUrl, $now, $now->modify("+{$this->ttlDays} days"));

        return $this->alerts->get($id);
    }
}
