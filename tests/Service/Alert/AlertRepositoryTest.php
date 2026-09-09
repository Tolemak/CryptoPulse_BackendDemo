<?php

namespace App\Tests\Service\Alert;

use App\Enum\AlertCondition;
use App\Enum\Pair;
use App\Exception\AlertNotFoundException;
use App\Service\Alert\AlertRepository;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Runs against a real Redis instance (REDIS_URL from .env.test).
 */
final class AlertRepositoryTest extends TestCase
{
    private Client $redis;
    private AlertRepository $repository;

    protected function setUp(): void
    {
        $this->redis = new Client($_ENV['REDIS_URL']);
        $this->redis->flushdb();
        $this->repository = new AlertRepository($this->redis);
    }

    protected function tearDown(): void
    {
        $this->redis->flushdb();
    }

    public function testSaveAndFindRoundTrips(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $this->repository->save('alert-1', Pair::BTC_USD, AlertCondition::Above, 60000.0, 'https://example.test/hook', $createdAt);

        $alert = $this->repository->find('alert-1');

        self::assertNotNull($alert);
        self::assertSame('alert-1', $alert->id);
        self::assertSame(Pair::BTC_USD, $alert->pair);
        self::assertSame(AlertCondition::Above, $alert->condition);
        self::assertSame(60000.0, $alert->threshold);
        self::assertSame('https://example.test/hook', $alert->webhookUrl);
        self::assertFalse($alert->fired);
        self::assertEquals($createdAt, $alert->createdAt);
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->find('does-not-exist'));
    }

    public function testGetThrowsForUnknownId(): void
    {
        $this->expectException(AlertNotFoundException::class);
        $this->repository->get('does-not-exist');
    }

    public function testFindByPairOnlyReturnsAlertsForThatPair(): void
    {
        $now = new \DateTimeImmutable();
        $this->repository->save('btc-1', Pair::BTC_USD, AlertCondition::Above, 60000.0, 'https://example.test/hook', $now);
        $this->repository->save('btc-2', Pair::BTC_USD, AlertCondition::Below, 50000.0, 'https://example.test/hook', $now);
        $this->repository->save('eth-1', Pair::ETH_USD, AlertCondition::Above, 3000.0, 'https://example.test/hook', $now);

        $btcAlerts = $this->repository->findByPair(Pair::BTC_USD);

        self::assertCount(2, $btcAlerts);
        self::assertSame(['btc-1', 'btc-2'], self::sortedIds($btcAlerts));
    }

    public function testMarkFiredPersists(): void
    {
        $now = new \DateTimeImmutable();
        $this->repository->save('alert-1', Pair::BTC_USD, AlertCondition::Above, 60000.0, 'https://example.test/hook', $now);

        $this->repository->markFired('alert-1', true);

        self::assertTrue($this->repository->get('alert-1')->fired);
    }

    public function testDeleteRemovesTheAlertAndItsPairIndexEntry(): void
    {
        $now = new \DateTimeImmutable();
        $this->repository->save('alert-1', Pair::BTC_USD, AlertCondition::Above, 60000.0, 'https://example.test/hook', $now);

        $this->repository->delete('alert-1');

        self::assertNull($this->repository->find('alert-1'));
        self::assertSame([], $this->repository->findByPair(Pair::BTC_USD));
    }

    /**
     * @param \App\Dto\AlertView[] $alerts
     * @return string[]
     */
    private static function sortedIds(array $alerts): array
    {
        $ids = array_map(static fn ($a) => $a->id, $alerts);
        sort($ids);

        return $ids;
    }
}
