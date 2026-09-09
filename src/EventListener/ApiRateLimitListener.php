<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
final class ApiRateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.api_inbound')]
        private readonly RateLimiterFactory $limiterFactory,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $limit = $this->limiterFactory->create($request->getClientIp() ?? 'unknown')->consume();

        if (!$limit->isAccepted()) {
            $response = new JsonResponse(['error' => 'Too many requests.'], 429);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));
            $event->setResponse($response);
        }
    }
}
