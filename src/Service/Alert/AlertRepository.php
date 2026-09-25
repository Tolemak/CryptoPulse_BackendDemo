<?php

namespace App\Service\Alert;

use App\Dto\AlertView;
use App\Enum\AlertCondition;
use App\Enum\Pair;
use App\Exception\AlertNotFoundException;
use Predis\ClientInterface;

/**
 * Talks to Predis directly — this is the alert system of record, not a cache.
 */
class AlertRepository
{
    private const string EXPIRY_INDEX = 'alerts:expiry';

    private const array REQUIRED_FIELDS = ['pair', 'condition', 'threshold', 'webhookUrl', 'createdAt', 'expiresAt', 'firedState'];

    // Compare-and-set, so an alert is claimed by one evaluator only and a
    // hash that has already expired is never recreated.
    private const string SWAP_FIRED_STATE = <<<'LUA'
        if redis.call('HGET', KEYS[1], 'firedState') == ARGV[1] then
            redis.call('HSET', KEYS[1], 'firedState', ARGV[2])
            return 1
        end
        return 0
        LUA;

    public function __construct(
        private readonly ClientInterface $redis,
    ) {
    }

    public function save(string $id, Pair $pair, AlertCondition $condition, float $threshold, string $webhookUrl, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): void
    {
        $this->redis->multi();
        $this->redis->hmset(self::hashKey($id), [
            'pair' => $pair->value,
            'condition' => $condition->value,
            'threshold' => (string) $threshold,
            'webhookUrl' => $webhookUrl,
            'createdAt' => $createdAt->format(DATE_ATOM),
            'expiresAt' => $expiresAt->format(DATE_ATOM),
            'firedState' => '0',
        ]);
        $this->redis->expireat(self::hashKey($id), $expiresAt->getTimestamp());
        $this->redis->sadd(self::byPairKey($pair), [$id]);
        $this->redis->zadd(self::EXPIRY_INDEX, [$id => $expiresAt->getTimestamp()]);
        $this->redis->exec();
    }

    public function find(string $id): ?AlertView
    {
        $data = $this->redis->hgetall(self::hashKey($id));
        if ($data === [] || $data === null) {
            return null;
        }

        return self::hydrate($id, $data);
    }

    public function get(string $id): AlertView
    {
        return $this->find($id) ?? throw new AlertNotFoundException($id);
    }

    /**
     * Ids whose alert has expired are dropped from the pair index on the way.
     *
     * @return AlertView[]
     */
    public function findByPair(Pair $pair): array
    {
        $alerts = [];
        $stale = [];
        foreach ($this->redis->smembers(self::byPairKey($pair)) as $id) {
            $alert = $this->find($id);
            if ($alert !== null) {
                $alerts[] = $alert;
            } else {
                $stale[] = $id;
            }
        }

        if ([] !== $stale) {
            $this->redis->srem(self::byPairKey($pair), $stale);
        }

        return $alerts;
    }

    public function countActive(\DateTimeImmutable $now): int
    {
        $this->redis->zremrangebyscore(self::EXPIRY_INDEX, '-inf', $now->getTimestamp());

        return $this->redis->zcard(self::EXPIRY_INDEX);
    }

    public function delete(string $id): void
    {
        $alert = $this->get($id);

        $this->redis->multi();
        $this->redis->del([self::hashKey($id)]);
        $this->redis->srem(self::byPairKey($alert->pair), [$id]);
        $this->redis->zrem(self::EXPIRY_INDEX, $id);
        $this->redis->exec();
    }

    /**
     * @return bool false when the alert is already fired or gone
     */
    public function claimFiring(string $id): bool
    {
        return $this->swapFiredState($id, '0', '1');
    }

    public function rearm(string $id): void
    {
        $this->swapFiredState($id, '1', '0');
    }

    private function swapFiredState(string $id, string $from, string $to): bool
    {
        return 1 === (int) $this->redis->eval(self::SWAP_FIRED_STATE, 1, self::hashKey($id), $from, $to);
    }

    /**
     * @param array<string, string> $data
     */
    private static function hydrate(string $id, array $data): ?AlertView
    {
        if ([] !== array_diff(self::REQUIRED_FIELDS, array_keys($data))) {
            return null;
        }

        $pair = Pair::tryFrom($data['pair']);
        $condition = AlertCondition::tryFrom($data['condition']);
        if (null === $pair || null === $condition) {
            return null;
        }

        return new AlertView(
            id: $id,
            pair: $pair,
            condition: $condition,
            threshold: (float) $data['threshold'],
            webhookUrl: $data['webhookUrl'],
            createdAt: new \DateTimeImmutable($data['createdAt']),
            expiresAt: new \DateTimeImmutable($data['expiresAt']),
            fired: '1' === $data['firedState'],
        );
    }

    private static function hashKey(string $id): string
    {
        return "alert:{$id}";
    }

    private static function byPairKey(Pair $pair): string
    {
        return "alerts:by_pair:{$pair->value}";
    }
}
