<?php

namespace App\Tests\Service\Alert;

use App\Enum\AlertCondition;
use App\Enum\Pair;
use App\Exception\AlertCapacityReachedException;
use App\Service\Alert\AlertRegistry;
use App\Service\Alert\AlertRepository;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Symfony\Component\Uid\Uuid;

final class AlertRegistryTest extends TestCase
{
    private AlertRepository $repository;

    protected function setUp(): void
    {
        $redis = new Client($_ENV['REDIS_URL']);
        $redis->flushdb();
        $this->repository = new AlertRepository($redis);
    }

    public function testRegisterStoresAnExpiringAlertUnderAUuid(): void
    {
        $alert = (new AlertRegistry($this->repository, ttlDays: 7))->register(Pair::BTC_USD, AlertCondition::Below, 50000.0, 'https://example.test/hook');

        self::assertTrue(Uuid::isValid($alert->id));
        self::assertSame(7, $alert->createdAt->diff($alert->expiresAt)->days);
        self::assertSame(1, $this->repository->countActive(new \DateTimeImmutable()));
    }

    public function testRegisterRefusesOnceTheCapIsReached(): void
    {
        $registry = new AlertRegistry($this->repository, maxActive: 2);
        $registry->register(Pair::BTC_USD, AlertCondition::Above, 1.0, 'https://example.test/hook');
        $registry->register(Pair::ETH_USD, AlertCondition::Above, 1.0, 'https://example.test/hook');

        $this->expectException(AlertCapacityReachedException::class);
        $registry->register(Pair::SOL_USD, AlertCondition::Above, 1.0, 'https://example.test/hook');
    }
}
