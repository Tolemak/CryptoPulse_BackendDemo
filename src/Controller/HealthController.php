<?php

namespace App\Controller;

use Predis\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness probe for uptime monitors. Redis is the only store, so one PING
 * stands in for the dependency check; no upstream exchange is ever called and
 * failure details are never returned.
 */
final readonly class HealthController
{
    public function __construct(private ClientInterface $redis)
    {
    }

    #[Route('/api/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->redis->ping();
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false], 503);
        }

        return new JsonResponse(['ok' => true]);
    }
}
