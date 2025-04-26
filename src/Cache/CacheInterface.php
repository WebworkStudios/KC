<?php

namespace Src\Cache;

/**
 * PSR-16 Simple Cache Interface
 *
 * Dies ist eine vereinfachte Version des PSR-16 Interfaces, angepasst für
 * das PHP 8.4 ADR-Framework
 */
interface CacheInterface
{
    /**
     * Holt einen Wert aus dem Cache
     *
     * @param string $key Cache-Schlüssel
     * @param mixed $default Standardwert, falls Schlüssel nicht existiert
     * @return mixed Gespeicherter Wert oder Default-Wert
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Speichert einen Wert im Cache
     *
     * @param string $key Cache-Schlüssel
     * @param mixed $value Zu speichernder Wert
     * @param int|null $ttl Time-to-live in Sekunden, null bedeutet unbegrenzt
     * @return bool True bei Erfolg, false bei Fehler
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Löscht einen Wert aus dem Cache
     *
     * @param string $key Cache-Schlüssel
     * @return bool True bei Erfolg, false bei Fehler
     */
    public function delete(string $key): bool;

    /**
     * Leert den gesamten Cache
     *
     * @return bool True bei Erfolg, false bei Fehler
     */
    public function clear(): bool;

    /**
     * Holt mehrere Werte aus dem Cache
     *
     * @param array $keys Cache-Schlüssel
     * @param mixed $default Standardwert für nicht vorhandene Schlüssel
     * @return array Assoziatives Array mit Schlüssel-Wert-Paaren
     */
    public function getMultiple(array $keys, mixed $default = null): array;

    /**
     * Speichert mehrere Werte im Cache
     *
     * @param array $values Assoziatives Array mit Schlüssel-Wert-Paaren
     * @param int|null $ttl Time-to-live in Sekunden, null bedeutet unbegrenzt
     * @return bool True bei Erfolg, false bei Fehler
     */
    public function setMultiple(array $values, ?int $ttl = null): bool;

    /**
     * Löscht mehrere Werte aus dem Cache
     *
     * @param array $keys Cache-Schlüssel
     * @return bool True bei Erfolg, false bei Fehler
     */
    public function deleteMultiple(array $keys): bool;

    /**
     * Prüft, ob ein Schlüssel im Cache existiert
     *
     * @param string $key Cache-Schlüssel
     * @return bool True, wenn der Schlüssel existiert
     */
    public function has(string $key): bool;
}