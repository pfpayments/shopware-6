<?php

declare(strict_types=1);

namespace PostFinanceCheckoutPayment\Core\PluginCore\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use PostFinanceCheckout\PluginCore\SharedKernel\CacheInterface;

/**
 * Stores plugin-core's cached data in Shopware's object cache.
 */
class PluginCoreCache implements CacheInterface
{
    public const KEY_PREFIX = 'postfinancecheckout_plugin_core.';
    public const TAG = 'postfinancecheckout_plugin_core';

    /**
     * @param CacheItemPoolInterface $pool
     */
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * Returns the cached value.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->pool->getItem($this->encode($key));
        return $item->isHit() ? $item->get() : $default;
    }

    /**
     * Stores a value in cache.
     *
     * @param string $key
     * @param mixed $value
     * @param null|int|\DateInterval $ttl
     * @return bool
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if ($this->isExpired($ttl)) {
            return $this->delete($key);
        }

        $item = $this->pool->getItem($this->encode($key));
        $item->set($value);
        $item->expiresAfter($ttl);

        if ($this->pool instanceof TagAwareAdapterInterface && method_exists($item, 'tag')) {
            $item->tag(self::TAG);
        }
        return $this->pool->save($item);
    }

    /**
     * Deletes an entry.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        return $this->pool->deleteItem($this->encode($key));
    }

    /**
     * Deletes all plugin-core entries.
     *
     * @return bool
     */
    public function clear(): bool
    {
        if ($this->pool instanceof TagAwareAdapterInterface) {
            return $this->pool->invalidateTags([self::TAG]);
        }
        return false;
    }

    /**
     * Returns the cached values for the given keys.
     *
     * @param iterable<string> $keys
     * @param mixed|null $default
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }
        return $values;
    }

    /**
     * Stores several values in cache.
     *
     * @param iterable<string, mixed> $values
     * @param null|int|\DateInterval $ttl
     * @return bool
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $success = true;
        foreach ($values as $key => $value) {
            $success = $this->set((string) $key, $value, $ttl) && $success;
        }
        return $success;
    }

    /**
     * Deletes several entries.
     *
     * @param iterable<string> $keys
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }
        return $success;
    }

    /**
     * Returns whether the key is cached.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->pool->hasItem($this->encode($key));
    }

    /**
     * Turns a plugin-core key into a valid cache pool key.
     *
     * @param string $key
     * @return string
     */
    private function encode(string $key): string
    {
        return self::KEY_PREFIX . rawurlencode($key);
    }

    /**
     * Returns whether the TTL is zero or negative, which PSR-16 treats as already expired.
     *
     * @param null|int|\DateInterval $ttl
     * @return bool
     */
    private function isExpired(null|int|\DateInterval $ttl): bool
    {
        if ($ttl === null) {
            return false;
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            return $now->add($ttl) <= $now;
        }

        return $ttl <= 0;
    }
}
