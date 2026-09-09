<?php

namespace App\Controller;

use App\Dto\CreateAlertRequest;
use App\Service\Alert\AlertRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * No auth: whoever holds an alert's id can read or delete it.
 */
#[Route('/api/alerts')]
final class AlertController extends AbstractController
{
    public function __construct(
        private readonly AlertRepository $alerts,
    ) {
    }

    #[Route('', name: 'alerts_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateAlertRequest $request): JsonResponse
    {
        $id = Uuid::v7()->toRfc4122();
        $createdAt = new \DateTimeImmutable();

        $this->alerts->save($id, $request->pair, $request->condition, $request->threshold, $request->webhookUrl, $createdAt);

        return $this->json($this->alerts->get($id), 201);
    }

    #[Route('/{id}', name: 'alerts_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->json($this->alerts->get($id));
    }

    #[Route('/{id}', name: 'alerts_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->alerts->delete($id);

        return $this->json(null, 204);
    }
}
