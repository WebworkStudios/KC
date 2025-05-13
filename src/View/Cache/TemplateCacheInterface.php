<?php

declare(strict_types=1);

namespace Src\View\Cache;

/**
 * Interface für Template-Cache
 *
 * Ermöglicht das Caching von kompilierten Templates
 */
interface TemplateCacheInterface
{
    /**
     * Prüft, ob ein kompiliertes Template für einen Namen verfügbar ist
     *
     * @param string $name Template-Name
     * @return bool True, wenn ein kompiliertes Template verfügbar ist
     */
    public function has(string $name): bool;

    /**
     * Gibt den kompilierten Code eines Templates zurück
     *
     * @param string $name Template-Name
     * @return string|null Kompilierter Code oder null, wenn nicht verfügbar
     */
    public function get(string $name): ?string;

    /**
     * Speichert den kompilierten Code eines Templates
     *
     * @param string $name Template-Name
     * @param string $code Kompilierter Code
     * @return bool True bei Erfolg
     */
    public function put(string $name, string $code): bool;

    /**
     * Prüft, ob ein Template neu kompiliert werden muss
     *
     * @param string $name Template-Name
     * @param int $lastModified Zeitstempel der letzten Änderung des Templates
     * @return bool True, wenn das Template neu kompiliert werden muss
     */
    public function needsRecompilation(string $name, int $lastModified): bool;

    /**
     * Entfernt ein kompiliertes Template aus dem Cache
     *
     * @param string $name Template-Name
     * @return bool True, wenn das Template entfernt wurde
     */
    public function forget(string $name): bool;

    /**
     * Leert den Cache
     *
     * @return bool True bei Erfolg
     */
    public function clear(): bool;

    /**
     * Gibt den Pfad zu einem ausführbaren kompilierten Template zurück
     *
     * @param string $name Template-Name
     * @return string|null Pfad zum ausführbaren Template oder null, wenn nicht verfügbar
     */
    public function getPath(string $name): ?string;
}