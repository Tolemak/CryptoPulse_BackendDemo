<?php

namespace App\Service\Alert;

use App\Dto\AlertView;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebhookNotifier
{
    private const int MAX_ATTEMPTS = 3;
    private const int RETRY_DELAY_MS = 250;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(AlertView $alert, float $price): bool
    {
        $payload = [
            'alertId' => $alert->id,
            'pair' => $alert->pair->value,
            'condition' => $alert->condition->value,
            'threshold' => $alert->threshold,
            'price' => $price,
            'firedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                $response = $this->client->request('POST', $alert->webhookUrl, ['json' => $payload]);
                $response->getStatusCode(); // triggers the actual request/throws on transport failure

                return true;
            } catch (ExceptionInterface $e) {
                $this->logger->warning('Webhook delivery attempt failed.', [
                    'alertId' => $alert->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_MS * 1000);
                }
            }
        }

        $this->logger->error('Webhook delivery failed after all retries.', ['alertId' => $alert->id]);

        return false;
    }
}
