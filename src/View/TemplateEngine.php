<?php

declare(strict_types=1);

namespace Src\View;

use Src\View\Cache\TemplateCacheInterface;
use Src\View\Compiler\TemplateCompiler;
use Src\View\Exception\TemplateException;
use Src\View\Loader\TemplateLoaderInterface;

/**
 * Leistungsstarke Template-Engine für PHP 8.4
 */
class TemplateEngine
{
    /**
     * Template-Loader
     *
     * @var TemplateLoaderInterface
     */
    private TemplateLoaderInterface $loader;

    /**
     * Template-Cache
     *
     * @var TemplateCacheInterface
     */
    private TemplateCacheInterface $cache;

    /**
     * Template-Compiler
     *
     * @var TemplateCompiler
     */
    private TemplateCompiler $compiler;

    /**
     * Registrierte Hilfsfunktionen
     *
     * @var array<string, callable>
     */
    private array $functions = [];

    /**
     * Output-Filter
     *
     * @var array<callable>
     */
    private array $outputFilters = [];

    /**
     * Template-Variablen
     *
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * Aktuelles Layout
     *
     * @var string|null
     */
    private ?string $layout = null;

    /**
     * Gespeicherte Sections
     *
     * @var array<string, string>
     */
    private array $sections = [];

    /**
     * Aktuell aktive Section
     *
     * @var string|null
     */
    private ?string $currentSection = null;

    /**
     * Stack für Parent-Templates
     *
     * @var array<string>
     */
    private array $templateStack = [];

    /**
     * Stack für Komponenten
     *
     * @var array<array{name: string, data: array<string, mixed>}>
     */
    private array $components = [];

    /**
     * Stack für Slots
     *
     * @var array<string>
     */
    private array $slots = [];

    /**
     * Aktuell aktiver Slot
     *
     * @var string|null
     */
    private ?string $currentSlot = null;

    /**
     * Temporäre Slots-Inhalte
     *
     * @var array<string, string>
     */
    private array $slotContents = [];

    /**
     * Erstellt eine neue Template-Engine
     *
     * @param TemplateLoaderInterface $loader Template-Loader
     * @param TemplateCacheInterface $cache Template-Cache
     * @param TemplateCompiler|null $compiler Template-Compiler
     */
    public function __construct(
        TemplateLoaderInterface $loader,
        TemplateCacheInterface $cache,
        ?TemplateCompiler $compiler = null
    ) {
        $this->loader = $loader;
        $this->cache = $cache;
        $this->compiler = $compiler ?? new TemplateCompiler();

        // Standard-Hilfsfunktionen registrieren
        $this->registerDefaultFunctions();
    }

    /**
     * Registriert einen Function-Provider
     *
     * @param FunctionProviderInterface $provider Function-Provider
     * @return self
     */
    public function registerFunctionProvider(FunctionProviderInterface $provider): self
    {
        // Alle Funktionen des Providers registrieren
        foreach ($provider->getFunctions() as $name => $callback) {
            $this->registerFunction($name, $callback);
        }

        return $this;
    }

    /**
     * Registriert eine Hilfsfunktion
     *
     * @param string $name Funktionsname
     * @param callable $callback Callback-Funktion
     * @return self
     */
    public function registerFunction(string $name, callable $callback): self
    {
        $this->functions[$name] = $callback;
        return $this;
    }

    /**
     * Fügt einen Output-Filter hinzu
     *
     * @param callable $filter Filter-Funktion
     * @return self
     */
    public function addOutputFilter(callable $filter): self
    {
        $this->outputFilters[] = $filter;
        return $this;
    }

    /**
     * Setzt Daten für das Template
     *
     * @param string|array<string, mixed> $key Schlüssel oder assoziatives Array
     * @param mixed $value Wert (wenn $key ein String ist)
     * @return self
     */
    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }

        return $this;
    }

    /**
     * Setzt das Layout
     *
     * @param string|null $name Layout-Name oder null für kein Layout
     * @return self
     */
    public function layout(?string $name = null): self
    {
        $this->layout = $name;
        return $this;
    }

    /**
     * Rendert ein Template
     *
     * @param string $name Template-Name
     * @param array<string, mixed> $data Template-Daten (zusätzlich zu globalen Daten)
     * @return string Gerenderter Inhalt
     * @throws TemplateException Wenn das Template nicht gefunden wird oder ein Fehler auftritt
     */
    public function render(string $name, array $data = []): string
    {
        // Daten zusammenführen
        $mergedData = array_merge($this->data, $data);

        // Template-Stack und Sections zurücksetzen
        $this->templateStack = [];
        $this->sections = [];

        // Layout merken, damit es nach dem Rendern wiederhergestellt werden kann
        $originalLayout = $this->layout;

        try {
            // Template kompilieren und ausführen
            $result = $this->renderTemplate($name, $mergedData);

            // Wenn ein Layout gesetzt ist, dieses mit dem Inhalt als "content" rendern
            if ($this->layout !== null) {
                $layoutData = array_merge($mergedData, ['content' => $result]);
                $result = $this->renderTemplate($this->layout, $layoutData);
            }

            // Output-Filter anwenden
            return $this->applyOutputFilters($result);
        } finally {
            // Layout zurücksetzen
            $this->layout = $originalLayout;
        }
    }

    /**
     * Rendert ein Template-String
     *
     * @param string $source Template-String
     * @param array<string, mixed> $data Template-Daten
     * @return string Gerenderter Inhalt
     */
    public function renderString(string $source, array $data = []): string
    {
        // Eindeutigen Namen für das Template generieren
        $name = 'string_' . md5($source);

        // Template kompilieren
        $compiled = $this->compiler->compile($source, $name);

        // In Cache speichern
        $this->cache->put($name, $compiled);

        // Template ausführen
        return $this->render($name, $data);
    }

    /**
     * Startet eine Section
     *
     * @param string $name Section-Name
     * @return void
     */
    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    /**
     * Beendet die aktuelle Section
     *
     * @return void
     * @throws TemplateException Wenn keine Section gestartet wurde
     */
    public function endSection(): void
    {
        if ($this->currentSection === null) {
            throw TemplateException::sectionNotStarted('unknown');
        }

        $this->sections[$this->currentSection] = ob_get_clean();
        $this->currentSection = null;
    }

    /**
     * Gibt den Inhalt einer Section aus
     *
     * @param string $name Section-Name
     * @param string $default Standard-Inhalt, wenn die Section nicht existiert
     * @return string Section-Inhalt
     */
    public function yieldContent(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Bindet ein Template ein
     *
     * @param string $name Template-Name
     * @param array<string, mixed> $data Template-Daten
     * @return string Gerenderter Inhalt
     */
    public function includeTemplate(string $name, array $data = []): string
    {
        return $this->renderTemplate($name, $data);
    }

    /**
     * Startet eine Komponente
     *
     * @param string $name Komponenten-Name
     * @param array<string, mixed> $data Komponenten-Daten
     * @return void
     */
    public function startComponent(string $name, array $data = []): void
    {
        ob_start();

        $this->components[] = [
            'name' => $name,
            'data' => $data
        ];
    }

    /**
     * Beendet die aktuelle Komponente
     *
     * @return string Gerenderter Komponenten-Inhalt
     * @throws TemplateException Wenn keine Komponente gestartet wurde
     */
    public function endComponent(): string
    {
        if (empty($this->components)) {
            throw new TemplateException("Cannot end component because no component was started");
        }

        $content = ob_get_clean();
        $component = array_pop($this->components);

        // Komponenten-Daten mit Slot-Inhalten zusammenführen
        $data = array_merge($component['data'], ['slot' => $content], $this->slotContents);

        // Slots zurücksetzen
        $this->slotContents = [];

        // Komponenten-Template rendern
        return $this->renderTemplate('components/' . $component['name'], $data);
    }

    /**
     * Startet einen Slot
     *
     * @param string $name Slot-Name
     * @return void
     */
    public function startSlot(string $name): void
    {
        $this->slots[] = $name;
        $this->currentSlot = $name;
        ob_start();
    }

    /**
     * Beendet den aktuellen Slot
     *
     * @return void
     * @throws TemplateException Wenn kein Slot gestartet wurde
     */
    public function endSlot(): void
    {
        if (empty($this->slots)) {
            throw new TemplateException("Cannot end slot because no slot was started");
        }

        $name = array_pop($this->slots);
        $this->slotContents[$name] = ob_get_clean();
        $this->currentSlot = count($this->slots) > 0 ? end($this->slots) : null;
    }

    /**
     * Ruft eine registrierte Funktion auf
     *
     * @param string $name Funktionsname
     * @param array<mixed> $arguments Argumente
     * @return mixed Rückgabewert der Funktion
     * @throws TemplateException Wenn die Funktion nicht registriert ist
     */
    public function callFunction(string $name, array $arguments = []): mixed
    {
        if (!isset($this->functions[$name])) {
            throw TemplateException::functionNotRegistered($name);
        }

        return call_user_func_array($this->functions[$name], $arguments);
    }

    /**
     * Rendert ein Template
     *
     * @param string $name Template-Name
     * @param array<string, mixed> $data Template-Daten
     * @return string Gerenderter Inhalt
     * @throws TemplateException Wenn das Template nicht gefunden wird oder ein Fehler auftritt
     */
    private function renderTemplate(string $name, array $data): string
    {
        // Prüfen, ob das Template existiert
        if (!$this->loader->exists($name)) {
            throw TemplateException::templateNotFound($name);
        }

        // Template-Stack aktualisieren
        $this->templateStack[] = $name;

        try {
            // Prüfen, ob eine Neukompilierung erforderlich ist
            $needsRecompilation = $this->cache->needsRecompilation(
                $name,
                $this->loader->getLastModified($name)
            );

            // Wenn nötig, Template neu kompilieren
            if ($needsRecompilation) {
                $source = $this->loader->load($name);
                $compiled = $this->compiler->compile($source, $name);
                $this->cache->put($name, $compiled);
            }

            // Pfad zum kompilierten Template ermitteln
            $compiledPath = $this->cache->getPath($name);

            if ($compiledPath === null) {
                throw new TemplateException("Failed to get compiled template path for '{$name}'");
            }

            // Template mit Daten ausführen
            return $this->evaluateTemplate($compiledPath, $data);
        } catch (\Throwable $e) {
            // Fehler mit Template-Kontext werfen
            if (!$e instanceof TemplateException) {
                throw TemplateException::withContext(
                    $e->getMessage(),
                    $name,
                    null,
                    $e
                );
            }

            throw $e;
        } finally {
            // Template vom Stack entfernen
            array_pop($this->templateStack);
        }
    }

    /**
     * Gibt den Template-Loader zurück
     *
     * @return TemplateLoaderInterface
     */
    public function getLoader(): TemplateLoaderInterface
    {
        return $this->loader;
    }

    /**
     * Führt ein kompiliertes Template aus
     *
     * @param string $path Pfad zum kompilierten Template
     * @param array<string, mixed> $data Template-Daten
     * @return string Gerenderter Inhalt
     */
    private function evaluateTemplate(string $path, array $data): string
    {
        // Daten extrahieren, damit sie im Template verfügbar sind
        extract($data, EXTR_SKIP);

        // Output-Puffer starten
        ob_start();

        try {
            // Template einbinden
            include $path;
        } catch (\Throwable $e) {
            // Output-Puffer leeren
            ob_end_clean();
            throw $e;
        }

        // Gepufferten Inhalt zurückgeben
        return ob_get_clean();
    }

    /**
     * Wendet alle Output-Filter auf den Inhalt an
     *
     * @param string $content Gerenderter Inhalt
     * @return string Gefilterter Inhalt
     */
    private function applyOutputFilters(string $content): string
    {
        foreach ($this->outputFilters as $filter) {
            $content = $filter($content);
        }

        return $content;
    }

    /**
     * Registriert die Standard-Hilfsfunktionen
     *
     * @return void
     */
    private function registerDefaultFunctions(): void
    {
        // HTML-Escaping
        $this->registerFunction('e', function (mixed $value): string {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8', false);
        });

        // String-Funktionen
        $this->registerFunction('upper', function (string $value): string {
            return mb_strtoupper($value, 'UTF-8');
        });

        $this->registerFunction('lower', function (string $value): string {
            return mb_strtolower($value, 'UTF-8');
        });

        $this->registerFunction('capitalize', function (string $value): string {
            return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
        });

        $this->registerFunction('trim', function (string $value, string $chars = " \t\n\r\0\x0B"): string {
            return trim($value, $chars);
        });

        $this->registerFunction('truncate', function (string $value, int $length = 100, string $suffix = '...'): string {
            if (mb_strlen($value, 'UTF-8') <= $length) {
                return $value;
            }

            return rtrim(mb_substr($value, 0, $length, 'UTF-8')) . $suffix;
        });

        // Array-Funktionen
        $this->registerFunction('length', function (mixed $value): int {
            if (is_array($value) || $value instanceof \Countable) {
                return count($value);
            }

            return is_string($value) ? mb_strlen($value, 'UTF-8') : 0;
        });

        // Zeit und Datum
        $this->registerFunction('date', function (mixed $date, string $format = 'd.m.Y'): string {
            if ($date instanceof \DateTimeInterface) {
                return $date->format($format);
            }

            if (is_numeric($date)) {
                return date($format, (int)$date);
            }

            if (is_string($date)) {
                return date($format, strtotime($date));
            }

            return '';
        });

        // JSON
        $this->registerFunction('json', function (mixed $value, int $options = 0): string {
            return json_encode($value, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });

        // HTML und Text
        $this->registerFunction('nl2br', function (string $value): string {
            return nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false));
        });

        $this->registerFunction('raw', function (string $value): string {
            return $value;
        });
    }

    /**
     * Löscht den Template-Cache
     *
     * @return bool True bei Erfolg
     */
    public function clearCache(): bool
    {
        return $this->cache->clear();
    }

    /**
     * Löscht ein einzelnes Template aus dem Cache
     *
     * @param string $name Template-Name
     * @return bool True bei Erfolg
     */
    public function clearTemplateCache(string $name): bool
    {
        return $this->cache->forget($name);
    }
}