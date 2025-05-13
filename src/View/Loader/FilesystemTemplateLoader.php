<?php


declare(strict_types=1);

namespace Src\View\Loader;

use Src\View\Exception\TemplateException;

/**
 * Dateisystem-basierter Template-Loader
 *
 * Lädt Templates aus dem Dateisystem
 */
class FilesystemTemplateLoader implements TemplateLoaderInterface
{
    /**
     * Verzeichnis für Templates
     *
     * @var string
     */
    private string $basePath;

    /**
     * Template-Dateiendung
     *
     * @var string
     */
    private string $extension;

    /**
     * Cache für aufgelöste Template-Pfade
     *
     * @var array<string, string>
     */
    private array $resolvedPaths = [];

    /**
     * Erstellt einen neuen FilesystemTemplateLoader
     *
     * @param string $basePath Verzeichnis für Templates
     * @param string $extension Template-Dateiendung
     */
    public function __construct(string $basePath, string $extension = '.php')
    {
        $this->basePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;
        $this->extension = $extension[0] !== '.' ? '.' . $extension : $extension;

        // Prüfen, ob das Verzeichnis existiert und lesbar ist
        if (!is_dir($this->basePath)) {
            throw new TemplateException("Template directory '{$this->basePath}' does not exist");
        }

        if (!is_readable($this->basePath)) {
            throw new TemplateException("Template directory '{$this->basePath}' is not readable");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $name): bool
    {
        return file_exists($this->getPath($name));
    }

    /**
     * Ermittelt den vollständigen Pfad zu einem Template
     *
     * @param string $name Template-Name
     * @return string Vollständiger Pfad zum Template
     */
    private function getPath(string $name): string
    {
        if (isset($this->resolvedPaths[$name])) {
            return $this->resolvedPaths[$name];
        }

        // Slashes normalisieren
        $name = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $name);

        // Dateiendung hinzufügen, wenn nicht vorhanden
        if (!str_ends_with($name, $this->extension)) {
            $name .= $this->extension;
        }

        // Pfad erstellen und cachen
        $path = $this->basePath . $name;
        $this->resolvedPaths[$name] = $path;

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function load(string $name): string
    {
        $path = $this->getPath($name);

        if (!file_exists($path)) {
            throw TemplateException::templateNotFound($name);
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            throw new TemplateException("Failed to read template '{$name}' from '{$path}'");
        }

        return $content;
    }

    /**
     * {@inheritDoc}
     */
    public function getLastModified(string $name): int
    {
        $path = $this->getPath($name);

        if (!file_exists($path)) {
            throw TemplateException::templateNotFound($name);
        }

        $time = @filemtime($path);

        if ($time === false) {
            throw new TemplateException("Failed to get modification time for template '{$name}'");
        }

        return $time;
    }

    /**
     * Leert den Pfad-Cache
     *
     * @return void
     */
    public function clearPathCache(): void
    {
        $this->resolvedPaths = [];
    }
}