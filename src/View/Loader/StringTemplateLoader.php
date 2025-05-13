<?php


declare(strict_types=1);

namespace Src\View\Loader;

use Src\View\Exception\TemplateException;

/**
 * String-basierter Template-Loader
 *
 * Speichert Templates als Strings im Speicher - nützlich für Tests und dynamisch erzeugte Templates
 */
class StringTemplateLoader implements TemplateLoaderInterface
{
    /**
     * Gespeicherte Templates
     *
     * @var array<string, array{content: string, timestamp: int}>
     */
    private array $templates = [];

    /**
     * Erstellt einen neuen StringTemplateLoader
     *
     * @param array<string, string> $templates Initiale Templates (name => content)
     */
    public function __construct(array $templates = [])
    {
        foreach ($templates as $name => $content) {
            $this->add($name, $content);
        }
    }

    /**
     * Fügt ein Template hinzu oder aktualisiert es
     *
     * @param string $name Template-Name
     * @param string $content Template-Inhalt
     * @param int|null $timestamp Zeitstempel der letzten Änderung (null für aktuellen Zeitpunkt)
     * @return self
     */
    public function add(string $name, string $content, ?int $timestamp = null): self
    {
        $this->templates[$name] = [
            'content' => $content,
            'timestamp' => $timestamp ?? time()
        ];

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function load(string $name): string
    {
        if (!$this->exists($name)) {
            throw TemplateException::templateNotFound($name);
        }

        return $this->templates[$name]['content'];
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $name): bool
    {
        return isset($this->templates[$name]);
    }

    /**
     * {@inheritDoc}
     */
    public function getLastModified(string $name): int
    {
        if (!$this->exists($name)) {
            throw TemplateException::templateNotFound($name);
        }

        return $this->templates[$name]['timestamp'];
    }

    /**
     * Entfernt ein Template
     *
     * @param string $name Template-Name
     * @return bool True, wenn das Template existierte und entfernt wurde
     */
    public function remove(string $name): bool
    {
        if (!$this->exists($name)) {
            return false;
        }

        unset($this->templates[$name]);
        return true;
    }

    /**
     * Leert alle Templates
     *
     * @return self
     */
    public function clear(): self
    {
        $this->templates = [];
        return $this;
    }

    /**
     * Gibt alle Template-Namen zurück
     *
     * @return array<string> Array mit Template-Namen
     */
    public function getTemplateNames(): array
    {
        return array_keys($this->templates);
    }
}