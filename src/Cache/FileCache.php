<?php

namespace Src\Cache;

use FilesystemIterator;
use RuntimeException;
use Src\Log\LoggerInterface;
use Throwable;

/**
 * File-basierte Cache-Implementierung
 *
 * Speichert Cache-Einträge als Dateien im Dateisystem
 */
class FileCache extends AbstractCache
{
    /** @var string Verzeichnis für Cache-Dateien */
    private string $cacheDir;

    /** @var int Berechtigung für neu erstellte Verzeichnisse */
    private int $directoryPermission;

    /** @var int Berechtigung für neu erstellte Cache-Dateien */
    private int $filePermission;

    /** @var bool Verwendet eine tiefe Verzeichnisstruktur um Performance-Probleme zu vermeiden */
    private bool $useDeepDirectory;

    /**
     * Erstellt eine neue FileCache-Instanz
     *
     * @param string $cacheDir Verzeichnis für Cache-Dateien
     * @param string $prefix Präfix für alle Cache-Schlüssel
     * @param LoggerInterface|null $logger Optional: Logger für Cache-Operationen
     * @param int $directoryPermission Berechtigung für neu erstellte Verzeichnisse
     * @param int $filePermission Berechtigung für neu erstellte Dateien
     * @param bool $useDeepDirectory Verwendet eine tiefe Verzeichnisstruktur
     * @throws RuntimeException Wenn das Cache-Verzeichnis nicht erstellt werden kann
     */
    public function __construct(
        string           $cacheDir,
        string           $prefix = '',
        ?LoggerInterface $logger = null,
        int              $directoryPermission = 0775,
        int              $filePermission = 0664,
        bool             $useDeepDirectory = true
    )
    {
        parent::__construct($prefix, $logger);

        $this->cacheDir = rtrim($cacheDir, '/\\');
        $this->directoryPermission = $directoryPermission;
        $this->filePermission = $filePermission;
        $this->useDeepDirectory = $useDeepDirectory;

        // Verzeichnis erstellen, falls es nicht existiert
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, $this->directoryPermission, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException("Cache-Verzeichnis konnte nicht erstellt werden: {$this->cacheDir}");
        }

        $this->logger->debug("FileCache initialisiert", [
            'cache_dir' => $this->cacheDir,
            'prefix' => $this->prefix
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $prefixedKey = $this->prefixKey($key);
            $cacheFile = $this->getCacheFilePath($prefixedKey);

            if (!file_exists($cacheFile)) {
                $this->logOperation('get', $key, false, ['reason' => 'file_not_found']);
                return $default;
            }

            $content = file_get_contents($cacheFile);

            if ($content === false) {
                $this->logOperation('get', $key, false, ['reason' => 'read_error']);
                return $default;
            }

            $cacheData = $this->unserialize($content);

            // Prüfen, ob der Wert abgelaufen ist
            if ($this->isExpired($cacheData['expiry'])) {
                // Automatisch löschen
                $this->delete($key);
                $this->logOperation('get', $key, false, ['reason' => 'expired']);
                return $default;
            }

            $this->logOperation('get', $key, true, ['hit' => true]);
            return $cacheData['data'];
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Lesen aus dem Cache: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return $default;
        }
    }

    /**
     * Erzeugt den Dateipfad für einen Cache-Schlüssel
     *
     * @param string $key Cache-Schlüssel (bereits mit Präfix)
     * @return string Vollständiger Dateipfad
     */
    private function getCacheFilePath(string $key): string
    {
        $hash = hash('xxh3', $key);

        if ($this->useDeepDirectory) {
            // Aufteilen in Unterverzeichnisse, um Performance-Probleme bei vielen Dateien zu vermeiden
            $firstLevel = substr($hash, 0, 2);
            $secondLevel = substr($hash, 2, 2);

            return $this->cacheDir . '/' . $firstLevel . '/' . $secondLevel . '/' . $hash . '.cache';
        }

        return $this->cacheDir . '/' . $hash . '.cache';
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): bool
    {
        try {
            $prefixedKey = $this->prefixKey($key);
            $cacheFile = $this->getCacheFilePath($prefixedKey);

            if (!file_exists($cacheFile)) {
                // Ist kein Fehler, da der Schlüssel bereits nicht existiert
                $this->logOperation('delete', $key, true, ['exists' => false]);
                return true;
            }

            $result = unlink($cacheFile);
            $this->logOperation('delete', $key, $result);

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Löschen aus dem Cache: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            $prefixedKey = $this->prefixKey($key);
            $cacheFile = $this->getCacheFilePath($prefixedKey);

            // Verzeichnis erstellen, falls es nicht existiert
            $directory = dirname($cacheFile);
            if (!is_dir($directory) && !mkdir($directory, $this->directoryPermission, true) && !is_dir($directory)) {
                $this->logOperation('set', $key, false, ['reason' => 'directory_create_error']);
                throw new RuntimeException("Cache-Verzeichnis konnte nicht erstellt werden: $directory");
            }

            $expiry = $this->calculateExpiry($ttl);

            $cacheData = [
                'expiry' => $expiry,
                'data' => $value
            ];

            $serialized = $this->serialize($cacheData);
            $result = file_put_contents($cacheFile, $serialized, LOCK_EX);

            if ($result === false) {
                $this->logOperation('set', $key, false, ['reason' => 'write_error']);
                return false;
            }

            // Berechtigungen setzen
            chmod($cacheFile, $this->filePermission);

            $this->logOperation('set', $key, true, [
                'ttl' => $ttl,
                'expiry' => $expiry,
                'size' => strlen($serialized)
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Schreiben in den Cache: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        try {
            $this->logger->info("Cache wird geleert", ['cache_dir' => $this->cacheDir]);

            $success = $this->clearDirectory($this->cacheDir);

            $this->logOperation('clear', 'all', $success);
            return $success;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Leeren des Caches: " . $e->getMessage(), [
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * Löscht alle Dateien in einem Verzeichnis rekursiv, behält aber die Verzeichnisstruktur bei
     *
     * @param string $directory Zu löschendes Verzeichnis
     * @return bool True bei Erfolg
     */
    private function clearDirectory(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $success = true;

        $items = new FilesystemIterator($directory);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                // Rekursiv Unterverzeichnisse leeren
                $success = $this->clearDirectory($item->getPathname()) && $success;
            } elseif ($item->isFile()) {
                // Nur Cache-Dateien löschen
                if (pathinfo($item->getFilename(), PATHINFO_EXTENSION) === 'cache') {
                    if (!unlink($item->getPathname())) {
                        $this->logger->warning("Konnte Cache-Datei nicht löschen", [
                            'file' => $item->getPathname()
                        ]);
                        $success = false;
                    }
                }
            }
        }

        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        try {
            $prefixedKey = $this->prefixKey($key);
            $cacheFile = $this->getCacheFilePath($prefixedKey);

            if (!file_exists($cacheFile)) {
                $this->logOperation('has', $key, false, ['reason' => 'file_not_found']);
                return false;
            }

            $content = file_get_contents($cacheFile);

            if ($content === false) {
                $this->logOperation('has', $key, false, ['reason' => 'read_error']);
                return false;
            }

            $cacheData = $this->unserialize($content);

            // Prüfen, ob der Wert abgelaufen ist
            if ($this->isExpired($cacheData['expiry'])) {
                // Automatisch löschen
                $this->delete($key);
                $this->logOperation('has', $key, false, ['reason' => 'expired']);
                return false;
            }

            $this->logOperation('has', $key, true);
            return true;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Prüfen des Cache-Schlüssels: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * Löscht abgelaufene Cache-Einträge
     *
     * @return int Anzahl der gelöschten Cache-Einträge
     */
    public function gc(): int
    {
        try {
            $this->logger->info("Garbage Collection gestartet", ['cache_dir' => $this->cacheDir]);

            $count = $this->gcDirectory($this->cacheDir);

            $this->logger->info("Garbage Collection abgeschlossen", [
                'deleted_entries' => $count
            ]);

            return $count;
        } catch (Throwable $e) {
            $this->logger->error("Fehler bei Garbage Collection: " . $e->getMessage(), [
                'exception' => get_class($e)
            ]);

            return 0;
        }
    }

    /**
     * Führt Garbage Collection in einem Verzeichnis durch
     *
     * @param string $directory Zu bereinigendes Verzeichnis
     * @return int Anzahl der gelöschten Cache-Einträge
     */
    private function gcDirectory(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;
        $items = new FilesystemIterator($directory);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                // Rekursiv Unterverzeichnisse bereinigen
                $count += $this->gcDirectory($item->getPathname());
            } elseif ($item->isFile()) {
                // Nur Cache-Dateien prüfen
                if (pathinfo($item->getFilename(), PATHINFO_EXTENSION) === 'cache') {
                    try {
                        $content = file_get_contents($item->getPathname());

                        if ($content !== false) {
                            $cacheData = $this->unserialize($content);

                            // Prüfen, ob der Wert abgelaufen ist
                            if ($this->isExpired($cacheData['expiry'])) {
                                if (unlink($item->getPathname())) {
                                    $count++;
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        // Beschädigte Cache-Datei, löschen
                        $this->logger->warning("Beschädigte Cache-Datei gelöscht", [
                            'file' => $item->getPathname(),
                            'error' => $e->getMessage()
                        ]);

                        unlink($item->getPathname());
                        $count++;
                    }
                }
            }
        }

        return $count;
    }
}