<?php

namespace App\Tests\Controller;

use Predis\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testReportsOkWhenRedisAnswers(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('{"ok":true}', $client->getResponse()->getContent());
    }

    public function testIsNotConsumedByTheInboundRateLimit(): void
    {
        $client = static::createClient();

        for ($i = 0; $i < 70; ++$i) {
            $client->request('GET', '/api/health', server: ['REMOTE_ADDR' => '8.8.4.40']);
        }

        self::assertResponseStatusCodeSame(200);
    }

    public function testReportsUnavailableWithoutLeakingWhenRedisFails(): void
    {
        $client = static::createClient();
        $redis = $this->createStub(ClientInterface::class);
        $redis->method('__call')->willThrowException(new \RuntimeException('redis://secret-host:6379 refused'));
        static::getContainer()->set(ClientInterface::class, $redis);

        $client->request('GET', '/api/health');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('{"ok":false}', $client->getResponse()->getContent());
    }
}
