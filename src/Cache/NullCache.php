<?php

namespace Src\Cache;

/**
 * Null-Cache-Implementierung, die nichts speichert
 *
 * Nützlich für Tests oder wenn Caching deaktiviert werden soll
 */
class NullCache implements CacheInterface
{
    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $default;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMultiple(array $keys): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return false;
    }
}