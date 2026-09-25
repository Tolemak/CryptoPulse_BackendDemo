<?php

namespace App\Service\Alert;

use App\Dto\AlertTrigger;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Every webhook of a round is sent concurrently, so a poll waits for the
 * slowest receiver per attempt, not for the sum of all of them.
 */
class WebhookNotifier
{
    private const int MAX_ATTEMPTS = 3;
    private const int RETRY_DELAY_MS = 250;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<AlertTrigger> $triggers
     *
     * @return array<string, bool> delivered or not, keyed by alert id
     */
    public function notifyAll(array $triggers): array
    {
        $pending = [];
        foreach ($triggers as $trigger) {
            $pending[$trigger->alert->id] = $trigger;
        }
        $delivered = array_fill_keys(array_keys($pending), false);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && [] !== $pending; ++$attempt) {
            if ($attempt > 1) {
                usleep(self::RETRY_DELAY_MS * 1000);
            }

            foreach ($this->send($pending) as $alertId => $response) {
                if ($this->succeeded($alertId, $response, $attempt)) {
                    $delivered[$alertId] = true;
                    unset($pending[$alertId]);
                }
            }
        }

        foreach (array_keys($pending) as $alertId) {
            $this->logger->error('Webhook delivery failed after all retries.', ['alertId' => $alertId]);
        }

        return $delivered;
    }

    /**
     * @param array<string, AlertTrigger> $triggers
     *
     * @return array<string, ResponseInterface>
     */
    private function send(array $triggers): array
    {
        $responses = [];
        foreach ($triggers as $alertId => $trigger) {
            try {
                $responses[$alertId] = $this->client->request('POST', $trigger->alert->webhookUrl, ['json' => self::payload($trigger)]);
            } catch (ExceptionInterface $e) {
                $this->logger->warning('Webhook request could not be sent.', ['alertId' => $alertId, 'error' => $e->getMessage()]);
            }
        }

        return $responses;
    }

    private function succeeded(string $alertId, ResponseInterface $response, int $attempt): bool
    {
        try {
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Webhook delivery attempt failed.', ['alertId' => $alertId, 'attempt' => $attempt, 'error' => $e->getMessage()]);

            return false;
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Webhook answered with a non-2xx status.', ['alertId' => $alertId, 'attempt' => $attempt, 'status' => $status]);

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(AlertTrigger $trigger): array
    {
        return [
            'alertId' => $trigger->alert->id,
            'pair' => $trigger->alert->pair->value,
            'condition' => $trigger->alert->condition->value,
            'threshold' => $trigger->alert->threshold,
            'price' => $trigger->price,
            'firedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }
}
