<?php

namespace App\Service\Alert;

use App\Dto\AggregatedPrice;
use Psr\Log\LoggerInterface;

/**
 * Edge-triggered: notifies once when a condition first becomes true, then
 * resets silently when it crosses back — never re-fires while still crossed.
 */
final class AlertEvaluatorService
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly WebhookNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function evaluate(AggregatedPrice $price): void
    {
        foreach ($this->alerts->findByPair($price->pair) as $alert) {
            $crossed = $alert->condition->isCrossedBy($alert->threshold, $price->median);

            if ($crossed && !$alert->fired) {
                $this->logger->info('Alert condition crossed, notifying.', ['alertId' => $alert->id]);
                $this->notifier->notify($alert, $price->median);
                $this->alerts->markFired($alert->id, true);
            } elseif (!$crossed && $alert->fired) {
                $this->alerts->markFired($alert->id, false);
            }
        }
    }
}
