<?php

declare(strict_types=1);

namespace Src\View\Cache;

use Src\View\Exception\TemplateException;

/**
 * Dateisystem-basierter Template-Cache
 *
 * Speichert kompilierte Templates im Dateisystem
 */
class FilesystemTemplateCache implements TemplateCacheInterface
{
    /**
     * Cache-Verzeichnis
     *
     * @var string
     */
    private string $cacheDir;

    /**
     * Cache aktiv?
     *
     * @var bool
     */
    private bool $enabled;

    /**
     * Cache für Existenzprüfungen
     *
     * @var array<string, bool>
     */
    private array $existsCache = [];

    /**
     * Cache für Zeitstempel-Prüfungen
     *
     * @var array<string, int>
     */
    private array $timestampCache = [];

    /**
     * Erstellt einen neuen FilesystemTemplateCache
     *
     * @param string $cacheDir Cache-Verzeichnis
     * @param bool $enabled Cache aktiv?
     * @throws TemplateException Wenn das Cache-Verzeichnis nicht existiert oder nicht beschreibbar ist
     */
    public function __construct(string $cacheDir, bool $enabled = true)
    {
        $this->cacheDir = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR;
        $this->enabled = $enabled;

        // Prüfen, ob das Verzeichnis existiert und erstellen, falls nicht
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                throw new TemplateException("Cache directory '{$this->cacheDir}' could not be created");
            }
        }

        // Prüfen, ob das Verzeichnis beschreibbar ist
        if (!is_writable($this->cacheDir)) {
            throw new TemplateException("Cache directory '{$this->cacheDir}' is not writable");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $name): bool
    {
        // Cache-Prüfung überspringen, wenn Cache deaktiviert ist
        if (!$this->enabled) {
            return false;
        }

        // Existenz im lokalen Cache prüfen
        if (isset($this->existsCache[$name])) {
            return $this->existsCache[$name];
        }

        // Datei prüfen
        $path = $this->getPath($name);
        $exists = file_exists($path) && is_readable($path);

        // Im lokalen Cache speichern
        $this->existsCache[$name] = $exists;

        return $exists;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $name): ?string
    {
        // Cache-Abfrage überspringen, wenn Cache deaktiviert ist
        if (!$this->enabled) {
            return null;
        }

        // Prüfen, ob die Datei existiert
        if (!$this->has($name)) {
            return null;
        }

        // Datei lesen
        $path = $this->getPath($name);
        $content = @file_get_contents($path);

        if ($content === false) {
            // Aus lokalem Cache entfernen, da die Datei nicht gelesen werden konnte
            unset($this->existsCache[$name]);
            return null;
        }

        return $content;
    }

    /**
     * {@inheritDoc}
     */

    public function put(string $name, string $code): bool
    {
        // Cache-Update überspringen, wenn Cache deaktiviert ist
        if (!$this->enabled) {
            return true;
        }

        $path = $this->getPath($name);

        // Verzeichnisstruktur erstellen
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }

        // Datei direkt schreiben
        $result = file_put_contents($path, $code, LOCK_EX);

        if ($result === false) {
            return false;
        }

        // Zugriffsrechte setzen
        chmod($path, 0644);

        // Cache aktualisieren
        $this->existsCache[$name] = true;
        $this->timestampCache[$name] = time();

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function needsRecompilation(string $name, int $lastModified): bool
    {
        // Immer neu kompilieren, wenn Cache deaktiviert ist
        if (!$this->enabled) {
            return true;
        }

        // Prüfen, ob die Datei existiert
        if (!$this->has($name)) {
            return true;
        }

        $path = $this->getPath($name);

        // Zeitstempel der Cache-Datei ermitteln
        if (!isset($this->timestampCache[$name])) {
            $this->timestampCache[$name] = @filemtime($path);
        }

        // Wenn die Zeitstempel-Abfrage fehlgeschlagen ist, neu kompilieren
        if ($this->timestampCache[$name] === false) {
            return true;
        }

        // Neu kompilieren, wenn das Template neuer ist als der Cache
        return $lastModified > $this->timestampCache[$name];
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $name): bool
    {
        $path = $this->getPath($name);

        // Prüfen, ob die Datei existiert
        if (!file_exists($path)) {
            // Aus lokalem Cache entfernen
            unset($this->existsCache[$name]);
            unset($this->timestampCache[$name]);
            return true;
        }

        // Datei löschen
        $result = @unlink($path);

        if ($result) {
            // Aus lokalem Cache entfernen
            unset($this->existsCache[$name]);
            unset($this->timestampCache[$name]);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        // Cache-Verzeichnis leeren
        $success = $this->clearDirectory($this->cacheDir);

        // Lokale Caches zurücksetzen
        $this->existsCache = [];
        $this->timestampCache = [];

        return $success;
    }

    public function getPath(string $name): string
    {
        // Template-Name normalisieren: Slashes durch Unterstriche ersetzen und speziell kodieren
        $safeName = str_replace(['\\', '/', '.'], '_', $name);

        // Hash erstellen für lange Namen und zur Vermeidung von ungültigen Dateinamen
        $hash = md5($name);

        // Unterverzeichnisse basierend auf dem Hash erstellen (für bessere Performance)
        $subDir = substr($hash, 0, 2) . DIRECTORY_SEPARATOR . substr($hash, 2, 2);
        $fileName = $safeName . '_' . $hash . '.php';

        // Vollständigen Pfad zurückgeben
        $fullPath = $this->cacheDir . $subDir . DIRECTORY_SEPARATOR . $fileName;

        // Sicherstellen, dass das Verzeichnis existiert
        $dirPath = dirname($fullPath);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        return $fullPath;
    }

    public function debug(string $name): array
    {
        $path = $this->getPath($name);

        return [
            'cache_enabled' => $this->enabled,
            'cache_path' => $path,
            'file_exists' => file_exists($path),
            'is_readable' => is_readable($path),
            'directory_exists' => is_dir(dirname($path)),
            'directory_writable' => is_writable(dirname($path))
        ];
    }

    /**
     * Leert ein Verzeichnis rekursiv
     *
     * @param string $dir Zu leerendes Verzeichnis
     * @return bool True bei Erfolg
     */
    private function clearDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $success = true;
        $entries = scandir($dir);

        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            // . und .. überspringen
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                // Für Unterverzeichnisse rekursiv aufrufen
                if (!$this->clearDirectory($path)) {
                    $success = false;
                }

                // Versuchen, das (jetzt leere) Unterverzeichnis zu löschen
                if (!@rmdir($path)) {
                    $success = false;
                }
            } else {
                // Normale Dateien löschen
                if (!@unlink($path)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Gibt zurück, ob der Cache aktiviert ist
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Aktiviert oder deaktiviert den Cache
     *
     * @param bool $enabled Cache aktiviert?
     * @return self
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Gibt das Cache-Verzeichnis zurück
     *
     * @return string
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }
}