<?php

namespace App\Service\Alert;

use App\Dto\AggregatedPrice;
use App\Dto\AlertTrigger;
use Psr\Log\LoggerInterface;

/**
 * Edge-triggered: notifies once when a condition first becomes true, then
 * resets silently when it crosses back — never re-fires while still crossed.
 * An alert is claimed before its webhook goes out and re-armed if delivery
 * fails, so overlapping runs cannot notify twice and a failure is retried
 * on the next poll.
 */
final class AlertEvaluatorService
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly WebhookNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param AggregatedPrice[] $prices
     */
    public function evaluate(array $prices): void
    {
        $triggers = [];
        foreach ($prices as $price) {
            foreach ($this->alerts->findByPair($price->pair) as $alert) {
                $crossed = $alert->condition->isCrossedBy($alert->threshold, $price->median);

                if ($crossed && !$alert->fired && $this->alerts->claimFiring($alert->id)) {
                    $triggers[] = new AlertTrigger($alert, $price->median);
                } elseif (!$crossed && $alert->fired) {
                    $this->alerts->rearm($alert->id);
                }
            }
        }

        if ([] === $triggers) {
            return;
        }

        $this->logger->info('Alert conditions crossed, notifying.', ['count' => count($triggers)]);

        foreach ($this->notifier->notifyAll($triggers) as $alertId => $delivered) {
            if (!$delivered) {
                $this->alerts->rearm($alertId);
            }
        }
    }
}
