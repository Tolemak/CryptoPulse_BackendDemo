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
    public function __construct(
        private readonly ClientInterface $redis,
    ) {
    }

    public function save(string $id, Pair $pair, AlertCondition $condition, float $threshold, string $webhookUrl, \DateTimeImmutable $createdAt): void
    {
        $this->redis->hmset(self::hashKey($id), [
            'pair' => $pair->value,
            'condition' => $condition->value,
            'threshold' => (string) $threshold,
            'webhookUrl' => $webhookUrl,
            'createdAt' => $createdAt->format(DATE_ATOM),
            'firedState' => '0',
        ]);
        $this->redis->sadd(self::byPairKey($pair), [$id]);
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
     * @return AlertView[]
     */
    public function findByPair(Pair $pair): array
    {
        $ids = $this->redis->smembers(self::byPairKey($pair));

        $alerts = [];
        foreach ($ids as $id) {
            $alert = $this->find($id);
            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    public function delete(string $id): void
    {
        $alert = $this->get($id);
        $this->redis->del([self::hashKey($id)]);
        $this->redis->srem(self::byPairKey($alert->pair), [$id]);
    }

    public function markFired(string $id, bool $fired): void
    {
        $this->redis->hset(self::hashKey($id), 'firedState', $fired ? '1' : '0');
    }

    /**
     * @param array<string, string> $data
     */
    private static function hydrate(string $id, array $data): AlertView
    {
        return new AlertView(
            id: $id,
            pair: Pair::from($data['pair']),
            condition: AlertCondition::from($data['condition']),
            threshold: (float) $data['threshold'],
            webhookUrl: $data['webhookUrl'],
            createdAt: new \DateTimeImmutable($data['createdAt']),
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
