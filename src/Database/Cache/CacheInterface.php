<?php

namespace Src\Database\Cache;

/**
 * Schnittstelle für Cache-Provider im QueryBuilder
 */
interface CacheInterface
{
    /**
     * Speichert einen Wert im Cache
     *
     * @param string $key Cache-Schlüssel
     * @param mixed $value Zu cachender Wert
     * @param int|null $ttl Time-to-Live in Sekunden (null für unbegrenzt)
     * @param array<string> $tags Optionale Tags für den Cache-Eintrag
     * @return bool True bei Erfolg
     */
    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool;

    /**
     * Ruft einen Wert aus dem Cache ab
     *
     * @param string $key Cache-Schlüssel
     * @return mixed Wert oder null, wenn nicht gefunden
     */
    public function get(string $key): mixed;

    /**
     * Prüft, ob ein Schlüssel im Cache existiert
     *
     * @param string $key Cache-Schlüssel
     * @return bool True, wenn der Schlüssel existiert und nicht abgelaufen ist
     */
    public function has(string $key): bool;

    /**
     * Löscht einen Eintrag aus dem Cache
     *
     * @param string $key Cache-Schlüssel
     * @return bool True bei Erfolg
     */
    public function delete(string $key): bool;

    /**
     * Leert den gesamten Cache
     *
     * @return bool True bei Erfolg
     */
    public function clear(): bool;

    /**
     * Invalidiert alle Cache-Einträge mit einem bestimmten Tag
     *
     * @param string $tag Tag-Name
     * @return bool True bei Erfolg
     */
    public function invalidateByTag(string $tag): bool;

    /**
     * Gibt einen Wert aus dem Cache zurück oder speichert ihn, wenn er nicht existiert
     *
     * @param string $key Cache-Schlüssel
     * @param callable $callback Callback, der den zu cachenden Wert zurückgibt
     * @param int|null $ttl Time-to-Live in Sekunden (null für unbegrenzt)
     * @param array<string> $tags Optionale Tags für den Cache-Eintrag
     * @return mixed Wert aus dem Cache oder von der Callback-Funktion
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed;
}