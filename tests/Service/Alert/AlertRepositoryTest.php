<?php

namespace App\Tests\Service\Alert;

use App\Enum\AlertCondition;
use App\Enum\Pair;
use App\Exception\AlertNotFoundException;
use App\Service\Alert\AlertRepository;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Runs against a real Redis instance (REDIS_URL from phpunit.dist.xml).
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
        $expiresAt = new \DateTimeImmutable('+30 days');
        $this->repository->save('alert-1', Pair::BTC_USD, AlertCondition::Above, 60000.0, 'https://example.test/hook', $createdAt, $expiresAt);

        $alert = $this->repository->find('alert-1');

        self::assertNotNull($alert);
        self::assertSame('alert-1', $alert->id);
        self::assertSame(Pair::BTC_USD, $alert->pair);
        self::assertSame(AlertCondition::Above, $alert->condition);
        self::assertSame(60000.0, $alert->threshold);
        self::assertSame('https://example.test/hook', $alert->webhookUrl);
        self::assertFalse($alert->fired);
        self::assertEquals($createdAt, $alert->createdAt);
        self::assertSame($expiresAt->getTimestamp(), $alert->expiresAt->getTimestamp());
    }

    public function testSavedAlertExpiresInRedis(): void
    {
        $this->save('alert-1', Pair::BTC_USD, new \DateTimeImmutable('+1 hour'));

        self::assertEqualsWithDelta(3600, $this->redis->ttl('alert:alert-1'), 5);
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->find('does-not-exist'));
    }

    public function testFindReturnsNullForAnIncompleteHash(): void
    {
        $this->redis->hset('alert:partial', 'firedState', '1');
        $this->redis->hmset('alert:unknown-pair', [
            'pair' => 'NOPE_USD', 'condition' => 'above', 'threshold' => '1', 'webhookUrl' => 'https://example.test/hook',
            'createdAt' => '2026-01-01T00:00:00+00:00', 'expiresAt' => '2026-02-01T00:00:00+00:00', 'firedState' => '0',
        ]);

        self::assertNull($this->repository->find('partial'));
        self::assertNull($this->repository->find('unknown-pair'));
    }

    public function testGetThrowsForUnknownId(): void
    {
        $this->expectException(AlertNotFoundException::class);
        $this->repository->get('does-not-exist');
    }

    public function testFindByPairOnlyReturnsAlertsForThatPair(): void
    {
        $this->save('btc-1', Pair::BTC_USD);
        $this->save('btc-2', Pair::BTC_USD);
        $this->save('eth-1', Pair::ETH_USD);

        $btcAlerts = $this->repository->findByPair(Pair::BTC_USD);

        self::assertCount(2, $btcAlerts);
        self::assertSame(['btc-1', 'btc-2'], self::sortedIds($btcAlerts));
    }

    public function testFindByPairDropsIdsWhoseAlertExpired(): void
    {
        $this->save('btc-1', Pair::BTC_USD);
        $this->save('btc-2', Pair::BTC_USD);
        $this->redis->del(['alert:btc-2']);

        self::assertSame(['btc-1'], self::sortedIds($this->repository->findByPair(Pair::BTC_USD)));
        self::assertSame(['btc-1'], $this->redis->smembers('alerts:by_pair:BTC_USD'));
    }

    public function testCountActiveIgnoresExpiredAlerts(): void
    {
        $now = new \DateTimeImmutable();
        $this->save('live-1', Pair::BTC_USD, $now->modify('+1 day'));
        $this->save('live-2', Pair::ETH_USD, $now->modify('+1 day'));
        $this->save('gone', Pair::ETH_USD, $now->modify('-1 second'));

        self::assertSame(2, $this->repository->countActive($now));
    }

    public function testClaimFiringSucceedsOnlyOnce(): void
    {
        $this->save('alert-1', Pair::BTC_USD);

        self::assertTrue($this->repository->claimFiring('alert-1'));
        self::assertFalse($this->repository->claimFiring('alert-1'));
        self::assertTrue($this->repository->get('alert-1')->fired);
    }

    public function testRearmAllowsTheNextClaim(): void
    {
        $this->save('alert-1', Pair::BTC_USD);
        $this->repository->claimFiring('alert-1');

        $this->repository->rearm('alert-1');

        self::assertFalse($this->repository->get('alert-1')->fired);
        self::assertTrue($this->repository->claimFiring('alert-1'));
    }

    public function testClaimAndRearmNeverRecreateAMissingAlert(): void
    {
        self::assertFalse($this->repository->claimFiring('gone'));
        $this->repository->rearm('gone');

        self::assertSame(0, $this->redis->exists('alert:gone'));
    }

    public function testDeleteRemovesTheAlertAndItsIndexEntries(): void
    {
        $this->save('alert-1', Pair::BTC_USD);

        $this->repository->delete('alert-1');

        self::assertNull($this->repository->find('alert-1'));
        self::assertSame([], $this->repository->findByPair(Pair::BTC_USD));
        self::assertSame(0, $this->repository->countActive(new \DateTimeImmutable()));
    }

    private function save(string $id, Pair $pair, ?\DateTimeImmutable $expiresAt = null): void
    {
        $now = new \DateTimeImmutable();
        $this->repository->save($id, $pair, AlertCondition::Above, 60000.0, 'https://example.test/hook', $now, $expiresAt ?? $now->modify('+1 day'));
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
