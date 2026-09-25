<?php

namespace App\Controller;

use App\Dto\CreateAlertRequest;
use App\Service\Alert\AlertRegistry;
use App\Service\Alert\AlertRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * No auth: whoever holds an alert's id can read or delete it.
 */
#[Route('/api/alerts')]
final class AlertController extends AbstractController
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly AlertRegistry $registry,
        #[Autowire(service: 'limiter.alert_create')]
        private readonly RateLimiterFactory $createLimiter,
    ) {
    }

    #[Route('', name: 'alerts_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateAlertRequest $payload, Request $request): JsonResponse
    {
        $limit = $this->createLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many alerts created from this address. Try again later.');
        }

        assert($payload->pair !== null && $payload->condition !== null);
        assert($payload->threshold !== null && $payload->webhookUrl !== null);

        return $this->json($this->registry->register($payload->pair, $payload->condition, $payload->threshold, $payload->webhookUrl), 201);
    }

    #[Route('/{id}', name: 'alerts_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->json($this->alerts->get($id));
    }

    #[Route('/{id}', name: 'alerts_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->alerts->delete($id);

        return $this->json(null, 204);
    }
}
