<?php

namespace App\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Predis\Client;

trait IsolatedRedis
{
    #[Before]
    protected function flushTestRedisBefore(): void
    {
        (new Client($_ENV['REDIS_URL']))->flushdb();
    }

    #[After]
    protected function flushTestRedisAfter(): void
    {
        (new Client($_ENV['REDIS_URL']))->flushdb();
    }
}
