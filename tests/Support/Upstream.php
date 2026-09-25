<?php

namespace App\Tests\Support;

use App\Service\Exchange\UpstreamThrottle;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class Upstream
{
    public static function unlimited(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_unlimited', 'policy' => 'token_bucket', 'limit' => 1000, 'rate' => ['interval' => '1 second', 'amount' => 1000]],
            new InMemoryStorage(),
        );
    }

    public static function exhausted(): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => 'test_exhausted', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $factory->create()->consume();

        return $factory;
    }

    public static function throttle(?ArrayAdapter $cache = null): UpstreamThrottle
    {
        return new UpstreamThrottle($cache ?? new ArrayAdapter(), new NullLogger());
    }
}
