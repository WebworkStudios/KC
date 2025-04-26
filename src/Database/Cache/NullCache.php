<?php


namespace Src\Database\Cache;

/**
 * Null-Cache-Implementierung, die keinen tatsächlichen Caching-Mechanismus verwendet
 *
 * Nützlich für Tests oder um Caching zu deaktivieren
 */
class NullCache implements CacheInterface
{
    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        // Tut nichts, gibt immer Erfolg zurück
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): mixed
    {
        // Immer Cache-Miss
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        // Immer Cache-Miss
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): bool
    {
        // Tut nichts, gibt immer Erfolg zurück
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        // Tut nichts, gibt immer Erfolg zurück
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateByTag(string $tag): bool
    {
        // Tut nichts, gibt immer Erfolg zurück
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
    {
        // Callback direkt ausführen, kein Caching
        return $callback();
    }
}