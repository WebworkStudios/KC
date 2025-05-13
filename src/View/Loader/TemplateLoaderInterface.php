<?php


declare(strict_types=1);

namespace Src\View\Loader;

use Src\View\Exception\TemplateException;

/**
 * Interface für Template-Loader
 *
 * Ermöglicht das Laden von Templates aus verschiedenen Quellen (Dateisystem, Datenbank, Cache, etc.)
 */
interface TemplateLoaderInterface
{
    /**
     * Prüft, ob ein Template existiert
     *
     * @param string $name Template-Name
     * @return bool True, wenn das Template existiert
     */
    public function exists(string $name): bool;

    /**
     * Lädt ein Template
     *
     * @param string $name Template-Name
     * @return string Template-Inhalt
     * @throws TemplateException Wenn das Template nicht geladen werden kann
     */
    public function load(string $name): string;

    /**
     * Gibt den Zeitpunkt der letzten Änderung eines Templates zurück
     *
     * @param string $name Template-Name
     * @return int Zeitstempel der letzten Änderung
     * @throws TemplateException Wenn das Template nicht existiert
     */
    public function getLastModified(string $name): int;
}