<?php

namespace App\Service\Price;

use App\Enum\Pair;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Entries are serialized PHP objects, and unserialize() skips the constructor:
 * an entry written before a DTO gained a property comes back with that
 * property uninitialized. Anything that is not a fully initialized instance of
 * the expected class is treated as a miss instead of reaching the caller.
 *
 * @template T of object
 */
final class PairObjectCache
{
    /**
     * @param class-string<T> $class
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $keyPrefix,
        private readonly string $class,
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * @param T $value
     */
    public function write(Pair $pair, object $value): void
    {
        $item = $this->cache->getItem($this->key($pair));
        $item->set($value);
        $item->expiresAfter($this->ttlSeconds);
        $this->cache->save($item);
    }

    /**
     * @return T|null
     */
    public function read(Pair $pair): ?object
    {
        return $this->valueOf($this->cache->getItem($this->key($pair)));
    }

    /**
     * Single round trip for all pairs, instead of one read per pair.
     *
     * @param list<Pair> $pairs
     *
     * @return array<string, T> keyed by Pair::value, misses omitted
     */
    public function readMany(array $pairs): array
    {
        $items = iterator_to_array($this->cache->getItems(array_map($this->key(...), $pairs)));

        $values = [];
        foreach ($pairs as $pair) {
            $item = $items[$this->key($pair)] ?? null;
            $value = null === $item ? null : $this->valueOf($item);
            if (null !== $value) {
                $values[$pair->value] = $value;
            }
        }

        return $values;
    }

    /**
     * @return T|null
     */
    private function valueOf(CacheItemInterface $item): ?object
    {
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return $value instanceof $this->class && self::isIntact($value) ? $value : null;
    }

    private static function isIntact(object $object): bool
    {
        foreach ((new \ReflectionObject($object))->getProperties() as $property) {
            if (!$property->isInitialized($object)) {
                return false;
            }

            $nested = $property->getValue($object);
            foreach (is_array($nested) ? $nested : [$nested] as $value) {
                if (is_object($value) && str_starts_with($value::class, 'App\\Dto\\') && !self::isIntact($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function key(Pair $pair): string
    {
        return $this->keyPrefix.'.'.$pair->value;
    }
}
