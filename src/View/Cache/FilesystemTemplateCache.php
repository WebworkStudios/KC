<?php

declare(strict_types=1);

namespace Src\View\Cache;

use Src\View\Exception\TemplateException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Stark verbesserte FilesystemTemplateCache
 *
 * Kritische Verbesserungen:
 * - Optimierte Pfad-Generierung mit xxhash
 * - Atomare Schreiboperationen für Concurrency-Sicherheit
 * - In-Memory-Caching für bessere Performance
 * - Robuste Fehlerbehandlung
 * - Garbage Collection und Cache-Optimierung
 * - Thread-sichere Operationen
 */
class FilesystemTemplateCache implements TemplateCacheInterface
{
    private string $cacheDir;
    private bool $enabled;

    /** @var array<string, bool> In-Memory-Cache für Existenzprüfungen */
    private array $existsCache = [];

    /** @var array<string, int> In-Memory-Cache für Zeitstempel */
    private array $timestampCache = [];

    /** @var array<string, string> In-Memory-Cache für Pfade */
    private array $pathCache = [];

    /** @var int Maximale Anzahl von Einträgen im In-Memory-Cache */
    private int $maxCacheSize = 1000;

    /** @var int Anzahl der Cache-Zugriffe für Statistiken */
    private int $accessCount = 0;

    /** @var int Anzahl der Cache-Hits */
    private int $hitCount = 0;

    public function __construct(string $cacheDir, bool $enabled = true)
    {
        $this->cacheDir = $this->normalizePath($cacheDir);
        $this->enabled = $enabled;

        if ($this->enabled) {
            $this->ensureCacheDirectory();
        }
    }

    /**
     * Normalisiert einen Pfad plattformunabhängig
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Stellt sicher, dass das Cache-Verzeichnis existiert und beschreibbar ist
     */
    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                throw new TemplateException("Cache directory '{$this->cacheDir}' could not be created");
            }
        }

        if (!is_writable($this->cacheDir)) {
            throw new TemplateException("Cache directory '{$this->cacheDir}' is not writable");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $name): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $this->accessCount++;

        // In-Memory-Cache prüfen
        if (isset($this->existsCache[$name])) {
            $this->hitCount++;
            return $this->existsCache[$name];
        }

        $path = $this->getPath($name);
        $exists = file_exists($path) && is_readable($path);

        // Cache-Größe begrenzen (LRU-ähnliche Strategie)
        $this->limitCacheSize($this->existsCache);
        $this->existsCache[$name] = $exists;

        return $exists;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $name): ?string
    {
        if (!$this->enabled || !$this->has($name)) {
            return null;
        }

        $path = $this->getPath($name);

        // Atomarer Lesezugriff mit Retry-Mechanismus für Concurrency
        $maxRetries = 3;
        $retryDelay = 10000; // 10ms in Mikrosekunden

        for ($i = 0; $i < $maxRetries; $i++) {
            // Versuche mit flock zu lesen für Thread-Sicherheit
            $handle = @fopen($path, 'r');
            if ($handle === false) {
                continue;
            }

            if (flock($handle, LOCK_SH)) { // Shared lock für Lesen
                $content = stream_get_contents($handle);
                flock($handle, LOCK_UN);
                fclose($handle);

                if ($content !== false) {
                    return $content;
                }
            } else {
                fclose($handle);
            }

            // Bei fehlgeschlagenem Lock kurz warten
            if ($i < $maxRetries - 1) {
                usleep($retryDelay);
                $retryDelay *= 2; // Exponential backoff
            }
        }

        // Fallback: Normale file_get_contents als letzter Versuch
        $content = @file_get_contents($path);
        if ($content !== false) {
            return $content;
        }

        // Aus Cache entfernen, da Lesen fehlgeschlagen
        $this->invalidateCache($name);
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $name, string $code): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $path = $this->getPath($name);
        $dir = dirname($path);

        // Verzeichnis erstellen
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }

        // Atomare Schreiboperation mit temporärer Datei
        $tempPath = $path . '.tmp.' . uniqid() . '.' . getmypid();

        try {
            // In temporäre Datei schreiben mit exklusivem Lock
            $handle = fopen($tempPath, 'w');
            if ($handle === false) {
                return false;
            }

            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                @unlink($tempPath);
                return false;
            }

            $result = fwrite($handle, $code);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            if ($result === false) {
                @unlink($tempPath);
                return false;
            }

            // Berechtigungen setzen
            chmod($tempPath, 0644);

            // Atomares Verschieben (rename ist atomar auf Unix-Systemen)
            if (!rename($tempPath, $path)) {
                @unlink($tempPath);
                return false;
            }

            // Cache aktualisieren
            $this->updateCache($name, true);

            return true;

        } catch (Throwable $e) {
            // Aufräumen bei Fehler
            @unlink($tempPath);
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function needsRecompilation(string $name, int $lastModified): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if (!$this->has($name)) {
            return true;
        }

        // Zeitstempel aus In-Memory-Cache
        if (isset($this->timestampCache[$name])) {
            return $lastModified > $this->timestampCache[$name];
        }

        // Zeitstempel von Datei lesen
        $path = $this->getPath($name);
        $cacheTime = @filemtime($path);

        if ($cacheTime === false) {
            $this->invalidateCache($name);
            return true;
        }

        // Cache aktualisieren
        $this->limitCacheSize($this->timestampCache);
        $this->timestampCache[$name] = $cacheTime;

        return $lastModified > $cacheTime;
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $name): bool
    {
        $path = $this->getPath($name);

        if (!file_exists($path)) {
            $this->invalidateCache($name);
            return true;
        }

        $result = @unlink($path);

        if ($result) {
            $this->invalidateCache($name);

            // Versuche, leere Verzeichnisse zu entfernen
            $this->cleanupEmptyDirectories(dirname($path));
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        $success = $this->clearDirectory($this->cacheDir);

        // In-Memory-Caches zurücksetzen
        $this->clearMemoryCache();

        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath(string $name): string
    {
        // Pfad-Cache prüfen
        if (isset($this->pathCache[$name])) {
            return $this->pathCache[$name];
        }

        // Optimierte Pfad-Generierung
        $safeName = $this->createSafeName($name);

        // xxhash ist schneller als md5, fallback auf md5 wenn xxhash nicht verfügbar
        $hash = function_exists('hash') && in_array('xxh3', hash_algos())
            ? hash('xxh3', $name)
            : md5($name);

        // Unterverzeichnisse für bessere Performance bei vielen Dateien
        $subDir1 = substr($hash, 0, 2);
        $subDir2 = substr($hash, 2, 2);

        $fileName = $safeName . '_' . substr($hash, 4, 8) . '.php';
        $fullPath = $this->cacheDir . $subDir1 . DIRECTORY_SEPARATOR .
            $subDir2 . DIRECTORY_SEPARATOR . $fileName;

        // Cache-Größe begrenzen
        $this->limitCacheSize($this->pathCache);
        $this->pathCache[$name] = $fullPath;

        return $fullPath;
    }

    /**
     * Erstellt einen sicheren Dateinamen aus dem Template-Namen
     */
    private function createSafeName(string $name): string
    {
        // Spezielle Zeichen ersetzen, aber Punkte und Bindestriche beibehalten
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $name);

        // Mehrfache Unterstriche reduzieren
        $safeName = preg_replace('/_+/', '_', $safeName);

        // Punkte am Anfang/Ende entfernen (Unix hidden files)
        $safeName = trim($safeName, '.');

        // Länge begrenzen aber aussagekräftig halten
        if (strlen($safeName) > 50) {
            // Versuche am letzten Unterstrich/Punkt zu kürzen
            $safeName = substr($safeName, 0, 50);
            $lastSeparator = max(strrpos($safeName, '_'), strrpos($safeName, '.'));
            if ($lastSeparator !== false && $lastSeparator > 30) {
                $safeName = substr($safeName, 0, $lastSeparator);
            }
        }

        return trim($safeName, '_') ?: 'template';
    }

    /**
     * Begrenzt die Größe eines In-Memory-Caches
     */
    private function limitCacheSize(array &$cache): void
    {
        if (count($cache) >= $this->maxCacheSize) {
            // Entferne älteste 20% der Einträge (LRU-ähnlich)
            $removeCount = (int)($this->maxCacheSize * 0.2);
            $cache = array_slice($cache, $removeCount, null, true);
        }
    }

    /**
     * Aktualisiert die Cache-Einträge für einen Template-Namen
     */
    private function updateCache(string $name, bool $exists): void
    {
        $this->limitCacheSize($this->existsCache);
        $this->existsCache[$name] = $exists;

        if ($exists) {
            $this->limitCacheSize($this->timestampCache);
            $this->timestampCache[$name] = time();
        }
    }

    /**
     * Invalidiert Cache-Einträge für einen Template-Namen
     */
    private function invalidateCache(string $name): void
    {
        unset(
            $this->existsCache[$name],
            $this->timestampCache[$name]
        );
    }

    /**
     * Leert ein Verzeichnis rekursiv
     */
    private function clearDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $success = true;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    if (!@rmdir($file->getRealPath())) {
                        $success = false;
                    }
                } else {
                    if (!@unlink($file->getRealPath())) {
                        $success = false;
                    }
                }
            }
        } catch (Throwable $e) {
            $success = false;
        }

        return $success;
    }

    /**
     * Entfernt leere Verzeichnisse rekursiv bis zum Cache-Root
     */
    private function cleanupEmptyDirectories(string $dir): void
    {
        if ($dir === $this->cacheDir || !is_dir($dir)) {
            return;
        }

        // Prüfen, ob Verzeichnis leer ist
        try {
            $files = @scandir($dir);
            if ($files === false || count($files) <= 2) { // nur . und ..
                if (@rmdir($dir)) {
                    // Rekursiv das Elternverzeichnis prüfen
                    $this->cleanupEmptyDirectories(dirname($dir));
                }
            }
        } catch (Throwable $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Gibt detaillierte Cache-Statistiken zurück
     */
    public function getStats(): array
    {
        $totalFiles = 0;
        $totalSize = 0;
        $oldestFile = null;
        $newestFile = null;

        if (!is_dir($this->cacheDir)) {
            return [
                'enabled' => $this->enabled,
                'cache_dir' => $this->cacheDir,
                'total_files' => 0,
                'total_size' => 0,
                'total_size_mb' => 0,
                'memory_cache_entries' => count($this->existsCache),
                'path_cache_entries' => count($this->pathCache),
                'timestamp_cache_entries' => count($this->timestampCache),
                'directory_writable' => false,
                'max_cache_size' => $this->maxCacheSize,
                'access_count' => $this->accessCount,
                'hit_count' => $this->hitCount,
                'hit_ratio' => 0
            ];
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $totalFiles++;
                    $size = $file->getSize();
                    $totalSize += $size;

                    $mtime = $file->getMTime();
                    if ($oldestFile === null || $mtime < $oldestFile) {
                        $oldestFile = $mtime;
                    }
                    if ($newestFile === null || $mtime > $newestFile) {
                        $newestFile = $mtime;
                    }
                }
            }
        } catch (Throwable $e) {
            // Bei Fehlern beim Durchsuchen
        }

        return [
            'enabled' => $this->enabled,
            'cache_dir' => $this->cacheDir,
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'average_file_size' => $totalFiles > 0 ? round($totalSize / $totalFiles) : 0,
            'memory_cache_entries' => count($this->existsCache),
            'path_cache_entries' => count($this->pathCache),
            'timestamp_cache_entries' => count($this->timestampCache),
            'directory_writable' => is_writable($this->cacheDir),
            'max_cache_size' => $this->maxCacheSize,
            'access_count' => $this->accessCount,
            'hit_count' => $this->hitCount,
            'hit_ratio' => $this->accessCount > 0 ? round(($this->hitCount / $this->accessCount) * 100, 1) : 0,
            'oldest_file' => $oldestFile ? date('Y-m-d H:i:s', $oldestFile) : null,
            'newest_file' => $newestFile ? date('Y-m-d H:i:s', $newestFile) : null
        ];
    }

    /**
     * Leert die In-Memory-Caches
     */
    public function clearMemoryCache(): void
    {
        $this->existsCache = [];
        $this->timestampCache = [];
        $this->pathCache = [];
        $this->accessCount = 0;
        $this->hitCount = 0;
    }

    /**
     * Setzt die maximale Cache-Größe
     */
    public function setMaxCacheSize(int $size): self
    {
        $this->maxCacheSize = max(100, $size); // Minimum 100 Einträge
        return $this;
    }

    /**
     * Gibt zurück, ob der Cache aktiviert ist
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Aktiviert oder deaktiviert den Cache
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Gibt das Cache-Verzeichnis zurück
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Erweiterte Debug-Informationen für einen spezifischen Template
     */
    public function debug(string $name): array
    {
        $path = $this->getPath($name);
        $dirPath = dirname($path);

        $info = [
            'cache_enabled' => $this->enabled,
            'template_name' => $name,
            'cache_path' => $path,
            'cache_dir' => $this->cacheDir,
            'safe_filename' => $this->createSafeName($name),
            'file_exists' => file_exists($path),
            'is_readable' => is_readable($path),
            'directory_path' => $dirPath,
            'directory_exists' => is_dir($dirPath),
            'directory_writable' => is_writable($dirPath),
            'in_exists_cache' => isset($this->existsCache[$name]),
            'in_timestamp_cache' => isset($this->timestampCache[$name]),
            'in_path_cache' => isset($this->pathCache[$name])
        ];

        if (file_exists($path)) {
            $info['file_size'] = filesize($path);
            $info['file_size_kb'] = round(filesize($path) / 1024, 2);
            $info['file_mtime'] = filemtime($path);
            $info['file_mtime_formatted'] = date('Y-m-d H:i:s', filemtime($path));
            $info['file_permissions'] = substr(sprintf('%o', fileperms($path)), -4);
            $info['file_age_seconds'] = time() - filemtime($path);
        }

        if (isset($this->timestampCache[$name])) {
            $info['cached_timestamp'] = $this->timestampCache[$name];
            $info['cached_timestamp_formatted'] = date('Y-m-d H:i:s', $this->timestampCache[$name]);
        }

        return $info;
    }

    /**
     * Garbage Collection - entfernt alte Cache-Dateien
     */
    public function gc(int $maxAge = 86400): int
    {
        if (!$this->enabled || !is_dir($this->cacheDir)) {
            return 0;
        }

        $deletedCount = 0;
        $cutoffTime = time() - $maxAge;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    if ($file->getMTime() < $cutoffTime) {
                        if (@unlink($file->getRealPath())) {
                            $deletedCount++;
                        }
                    }
                }
            }

            // In-Memory-Caches nach GC leeren, da sich Datei-Status geändert haben könnte
            $this->clearMemoryCache();

            // Leere Verzeichnisse aufräumen
            $this->cleanupEmptyDirectories($this->cacheDir);

        } catch (Throwable $e) {
            // GC-Fehler protokollieren, aber nicht weiterwerfen
        }

        return $deletedCount;
    }

    /**
     * Optimiert den Cache durch Reorganisation der Dateistruktur
     */
    public function optimize(): bool
    {
        if (!$this->enabled) {
            return true;
        }

        try {
            // Temporäres Verzeichnis für Reorganisation
            $tempDir = $this->cacheDir . 'temp_optimize_' . uniqid() . DIRECTORY_SEPARATOR;

            if (!mkdir($tempDir, 0755, true)) {
                return false;
            }

            $movedFiles = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            // Alle Cache-Dateien sammeln und in optimierte Struktur verschieben
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $fileName = $file->getFilename();

                    // Hash aus Dateiname extrahieren (falls möglich)
                    if (preg_match('/_([a-f0-9]{8,})\.php$/', $fileName, $matches)) {
                        $hash = $matches[1];
                        $newSubDir1 = substr($hash, 0, 2);
                        $newSubDir2 = substr($hash, 2, 2);

                        $newDir = $tempDir . $newSubDir1 . DIRECTORY_SEPARATOR . $newSubDir2 . DIRECTORY_SEPARATOR;

                        if (!is_dir($newDir)) {
                            mkdir($newDir, 0755, true);
                        }

                        $newPath = $newDir . $fileName;

                        if (rename($file->getRealPath(), $newPath)) {
                            $movedFiles++;
                        }
                    }
                }
            }

            // Altes Cache-Verzeichnis leeren (ohne das temp-Verzeichnis)
            $this->clearDirectoryExcept($this->cacheDir, basename($tempDir));

            // Optimierte Struktur zurück verschieben
            $tempIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($tempIterator as $file) {
                if ($file->isFile()) {
                    $relativePath = substr($file->getRealPath(), strlen($tempDir));
                    $targetPath = $this->cacheDir . $relativePath;
                    $targetDir = dirname($targetPath);

                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }

                    rename($file->getRealPath(), $targetPath);
                }
            }

            // Temporäres Verzeichnis entfernen
            $this->clearDirectory($tempDir);
            @rmdir($tempDir);

            // In-Memory-Caches leeren
            $this->clearMemoryCache();

            return true;

        } catch (Throwable $e) {
            // Bei Fehler aufräumen
            if (isset($tempDir) && is_dir($tempDir)) {
                $this->clearDirectory($tempDir);
                @rmdir($tempDir);
            }
            return false;
        }
    }

    /**
     * Leert ein Verzeichnis bis auf bestimmte Ausnahmen
     */
    private function clearDirectoryExcept(string $dir, string $except): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $success = true;
        $files = scandir($dir);

        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === $except) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $file;

            if (is_dir($path)) {
                if (!$this->clearDirectory($path) || !@rmdir($path)) {
                    $success = false;
                }
            } else {
                if (!@unlink($path)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Komprimiert den Cache durch Entfernung doppelter Einträge
     */
    public function compress(): int
    {
        if (!$this->enabled || !is_dir($this->cacheDir)) {
            return 0;
        }

        $removedCount = 0;
        $fileHashes = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            // Erste Iteration: Hashes sammeln
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $hash = md5_file($file->getRealPath());
                    if (isset($fileHashes[$hash])) {
                        // Duplikat gefunden, neuere Datei behalten
                        $existingFile = $fileHashes[$hash];
                        if ($file->getMTime() > filemtime($existingFile)) {
                            // Aktuelle Datei ist neuer, alte löschen
                            @unlink($existingFile);
                            $fileHashes[$hash] = $file->getRealPath();
                        } else {
                            // Existierende Datei ist neuer, aktuelle löschen
                            @unlink($file->getRealPath());
                        }
                        $removedCount++;
                    } else {
                        $fileHashes[$hash] = $file->getRealPath();
                    }
                }
            }

            if ($removedCount > 0) {
                $this->clearMemoryCache();
                $this->cleanupEmptyDirectories($this->cacheDir);
            }

        } catch (Throwable $e) {
            // Komprimierungsfehler ignorieren
        }

        return $removedCount;
    }
}