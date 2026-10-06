<?php

namespace App\Controller;

use Predis\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

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
