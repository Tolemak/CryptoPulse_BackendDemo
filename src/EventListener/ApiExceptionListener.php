<?php

namespace App\EventListener;

use App\Exception\AlertNotFoundException;
use App\Exception\PairNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Keeps /api/* error responses JSON instead of Symfony's default HTML pages.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiExceptionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        $previous = $exception->getPrevious();
        if ($previous instanceof ValidationFailedException) {
            $violations = [];
            foreach ($previous->getViolations() as $violation) {
                $violations[$violation->getPropertyPath()] = $violation->getMessage();
            }
            $event->setResponse(new JsonResponse(['error' => 'Validation failed.', 'violations' => $violations], 422));

            return;
        }

        $status = match (true) {
            $exception instanceof PairNotFoundException => 400,
            $exception instanceof AlertNotFoundException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };

        if ($status >= 500) {
            $this->logger->error('Unhandled exception on API route.', ['exception' => $exception]);
        }

        $message = 500 === $status && 'prod' === $this->environment
            ? 'Internal server error.'
            : $exception->getMessage();

        $event->setResponse(new JsonResponse(['error' => $message], $status));
    }
}
