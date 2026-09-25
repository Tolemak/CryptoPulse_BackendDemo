<?php

namespace App\Service\Exchange;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Local token bucket per upstream, plus a shared back-off window once the
 * upstream itself answers 429/418 - honouring Retry-After when it sends one.
 */
final class UpstreamThrottle
{
    private const int DEFAULT_BACKOFF_SECONDS = 60;
    private const int MAX_BACKOFF_SECONDS = 3600;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function tryAcquire(string $upstream, RateLimiterFactory $limiterFactory, int $tokens = 1): bool
    {
        if ($this->isBackingOff($upstream)) {
            $this->logger->warning('Upstream asked us to back off, skipping request.', ['upstream' => $upstream]);

            return false;
        }

        try {
            $accepted = $limiterFactory->create()->consume($tokens)->isAccepted();
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('Rate limiter cannot cover this request.', ['upstream' => $upstream, 'error' => $e->getMessage()]);

            return false;
        }

        if (!$accepted) {
            $this->logger->warning('Local rate limit exhausted, skipping request.', ['upstream' => $upstream]);
        }

        return $accepted;
    }

    public function isBackingOff(string $upstream): bool
    {
        return $this->cache->getItem(self::key($upstream))->isHit();
    }

    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function backOffIfThrottled(string $upstream, ResponseInterface $response): bool
    {
        if (!in_array($response->getStatusCode(), [418, 429], true)) {
            return false;
        }

        $this->backOff($upstream, self::retryAfterSeconds($response));

        return true;
    }

    public function backOff(string $upstream, int $seconds = self::DEFAULT_BACKOFF_SECONDS): void
    {
        $seconds = max(1, min($seconds, self::MAX_BACKOFF_SECONDS));

        $item = $this->cache->getItem(self::key($upstream));
        $item->set(true);
        $item->expiresAfter($seconds);
        $this->cache->save($item);

        $this->logger->warning('Upstream is throttling us, backing off.', ['upstream' => $upstream, 'seconds' => $seconds]);
    }

    private static function retryAfterSeconds(ResponseInterface $response): int
    {
        $value = $response->getHeaders(false)['retry-after'][0] ?? '';

        return ctype_digit($value) ? (int) $value : self::DEFAULT_BACKOFF_SECONDS;
    }

    private static function key(string $upstream): string
    {
        return 'upstream.backoff.'.$upstream;
    }
}
