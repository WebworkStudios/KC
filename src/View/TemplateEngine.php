<?php

declare(strict_types=1);

namespace Src\View;

use Src\View\Cache\TemplateCacheInterface;
use Src\View\Compiler\TemplateCompiler;
use Src\View\Exception\TemplateException;
use Src\View\Loader\TemplateLoaderInterface;
use Throwable;

/**
 * Verbesserte Template-Engine für PHP 8.4
 *
 * Kritische Verbesserungen:
 * - Korrigierte Parameter-Reihenfolge
 * - Verbesserte Fehlerbehandlung
 * - Optimierte Performance
 * - Bessere Debugging-Unterstützung
 */
class TemplateEngine
{
    private TemplateLoaderInterface $loader;
    private TemplateCacheInterface $cache;
    private TemplateCompiler $compiler;

    /** @var array<string, callable> */
    private array $functions = [];

    /** @var array<callable> */
    private array $outputFilters = [];

    /** @var array<string, mixed> */
    private array $data = [];

    private ?string $layout = null;

    /** @var array<string, string> */
    private array $sections = [];

    private ?string $currentSection = null;

    /** @var array<string> */
    private array $templateStack = [];

    /** @var array<array{name: string, data: array<string, mixed>}> */
    private array $components = [];

    /** @var array<string> */
    private array $slots = [];

    private ?string $currentSlot = null;

    /** @var array<string, string> */
    private array $slotContents = [];

    /** @var bool Debug-Modus aktiviert */
    private bool $debugMode = false;

    /** @var array<string> Debug-Logs */
    private array $debugLogs = [];

    /**
     * KORRIGIERTE Parameter-Reihenfolge!
     * Vorher war es: (loader, cache, compiler)
     * Jetzt logisch: (loader, compiler, cache)
     */
    public function __construct(
        TemplateLoaderInterface $loader,
        TemplateCompiler $compiler,
        TemplateCacheInterface $cache
    ) {
        $this->loader = $loader;
        $this->compiler = $compiler;
        $this->cache = $cache;

        $this->registerDefaultFunctions();
        $this->debugLog('TemplateEngine initialized with corrected parameter order');
    }

    /**
     * Aktiviert/deaktiviert den Debug-Modus
     */
    public function setDebugMode(bool $enabled): self
    {
        $this->debugMode = $enabled;
        return $this;
    }

    /**
     * Fügt einen Debug-Log-Eintrag hinzu
     */
    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debugMode) {
            $this->debugLogs[] = [
                'timestamp' => microtime(true),
                'message' => $message,
                'context' => $context
            ];
        }
    }

    /**
     * Gibt Debug-Logs zurück
     */
    public function getDebugLogs(): array
    {
        return $this->debugLogs;
    }

    /**
     * Verbesserte render-Methode mit besserer Fehlerbehandlung
     */
    public function render(string $name, array $data = []): string
    {
        $startTime = microtime(true);
        $this->debugLog("Starting render for template: {$name}");

        try {
            // Daten zusammenführen
            $mergedData = array_merge($this->data, $data);

            // Template-Stack und Sections zurücksetzen
            $this->templateStack = [];
            $this->sections = [];

            // Layout merken
            $originalLayout = $this->layout;

            // Template kompilieren und ausführen
            $result = $this->renderTemplate($name, $mergedData);

            // Layout anwenden, wenn gesetzt
            if ($this->layout !== null) {
                $this->debugLog("Applying layout: {$this->layout}");
                $layoutData = array_merge($mergedData, ['content' => $result]);
                $result = $this->renderTemplate($this->layout, $layoutData);
            }

            // Output-Filter anwenden
            $filteredResult = $this->applyOutputFilters($result);

            $renderTime = (microtime(true) - $startTime) * 1000;
            $this->debugLog("Template rendered successfully", [
                'template' => $name,
                'render_time_ms' => round($renderTime, 2),
                'output_size' => strlen($filteredResult)
            ]);

            return $filteredResult;

        } catch (Throwable $e) {
            $this->debugLog("Template render failed", [
                'template' => $name,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            // Layout zurücksetzen
            $this->layout = $originalLayout ?? null;

            throw $e;
        } finally {
            // Layout zurücksetzen
            $this->layout = $originalLayout ?? null;
        }
    }

    /**
     * Verbesserte Template-Rendering-Methode
     */
    private function renderTemplate(string $name, array $data): string
    {
        // Template-Existenz prüfen
        if (!$this->loader->exists($name)) {
            throw TemplateException::templateNotFound($name);
        }

        // Zirkuläre Abhängigkeiten prüfen
        if (in_array($name, $this->templateStack, true)) {
            throw new TemplateException(
                "Circular template dependency detected: " .
                implode(' -> ', $this->templateStack) . " -> {$name}"
            );
        }

        $this->templateStack[] = $name;
        $this->debugLog("Rendering template: {$name}");

        try {
            // Cache-Status prüfen
            $lastModified = $this->loader->getLastModified($name);
            $needsRecompilation = $this->cache->needsRecompilation($name, $lastModified);

            if ($needsRecompilation) {
                $this->debugLog("Recompiling template: {$name}");
                $source = $this->loader->load($name);
                $compiled = $this->compiler->compile($source, $name);

                if (!$this->cache->put($name, $compiled)) {
                    throw new TemplateException("Failed to cache compiled template: {$name}");
                }
            } else {
                $this->debugLog("Using cached template: {$name}");
            }

            // Kompiliertes Template ausführen
            $compiledPath = $this->cache->getPath($name);
            if ($compiledPath === null || !file_exists($compiledPath)) {
                throw new TemplateException("Compiled template not found: {$name}");
            }

            return $this->evaluateTemplate($compiledPath, $data);

        } catch (Throwable $e) {
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
            array_pop($this->templateStack);
        }
    }

    /**
     * Verbesserte Template-Ausführung mit besserer Isolation
     */
    private function evaluateTemplate(string $path, array $data): string
    {
        // Sichere Variable-Extraktion
        $extractedVars = [];
        foreach ($data as $key => $value) {
            // Nur gültige PHP-Variablennamen zulassen
            if (preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $key)) {
                $extractedVars[$key] = $value;
            }
        }

        // Template in isolierter Umgebung ausführen
        return $this->executeInIsolation($path, $extractedVars);
    }

    /**
     * Führt Template in isolierter Umgebung aus
     */
    private function executeInIsolation(string $path, array $data): string
    {
        $executeTemplate = function() use ($path, $data) {
            // Daten extrahieren
            extract($data, EXTR_SKIP);

            // Output-Buffer starten
            ob_start();

            try {
                include $path;
                return ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };

        return $executeTemplate();
    }

    /**
     * Verbesserte Funktion-Registrierung mit Validierung
     */
    public function registerFunction(string $name, callable $callback): self
    {
        // Funktionsname validieren
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid function name: {$name}");
        }

        // Überprüfen, ob es sich um eine PHP-interne Funktion handelt
        if (function_exists($name) && !isset($this->functions[$name])) {
            throw new \InvalidArgumentException(
                "Cannot override built-in PHP function: {$name}"
            );
        }

        $this->functions[$name] = $callback;
        $this->debugLog("Registered function: {$name}");

        return $this;
    }

    /**
     * Sichere Funktions-Aufrufe
     */
    public function callFunction(string $name, array $arguments = []): mixed
    {
        if (!isset($this->functions[$name])) {
            throw TemplateException::functionNotRegistered($name);
        }

        try {
            return call_user_func_array($this->functions[$name], $arguments);
        } catch (Throwable $e) {
            throw new TemplateException(
                "Error calling template function '{$name}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Verbesserte Standard-Funktionen mit mehr Sicherheit
     */
    private function registerDefaultFunctions(): void
    {
        // Sichere HTML-Escaping-Funktion
        $this->registerFunction('e', function (mixed $value): string {
            if ($value === null) {
                return '';
            }
            return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
        });

        // Verbesserte String-Funktionen
        $this->registerFunction('upper', function (mixed $value): string {
            return mb_strtoupper((string)$value, 'UTF-8');
        });

        $this->registerFunction('lower', function (mixed $value): string {
            return mb_strtolower((string)$value, 'UTF-8');
        });

        $this->registerFunction('trim', function (mixed $value, string $chars = " \t\n\r\0\x0B"): string {
            return trim((string)$value, $chars);
        });

        // Sichere Length-Funktion
        $this->registerFunction('length', function (mixed $value): int {
            if ($value === null) {
                return 0;
            }

            if (is_array($value) || $value instanceof \Countable) {
                return count($value);
            }

            if (is_string($value)) {
                return mb_strlen($value, 'UTF-8');
            }

            if (is_object($value) && method_exists($value, '__toString')) {
                return mb_strlen((string)$value, 'UTF-8');
            }

            return 0;
        });

        // Verbesserte Truncate-Funktion
        $this->registerFunction('truncate', function (
            mixed $value,
            int $length = 100,
            string $suffix = '...'
        ): string {
            $str = (string)$value;

            if (mb_strlen($str, 'UTF-8') <= $length) {
                return $str;
            }

            $truncated = mb_substr($str, 0, $length, 'UTF-8');

            // Nicht mitten im Wort abbrechen
            $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');
            if ($lastSpace !== false && $lastSpace > $length * 0.75) {
                $truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
            }

            return $truncated . $suffix;
        });

        // Sichere Datum-Formatierung
        $this->registerFunction('date', function (mixed $date, string $format = 'd.m.Y'): string {
            if ($date === null) {
                return '';
            }

            try {
                if ($date instanceof \DateTimeInterface) {
                    return $date->format($format);
                }

                if (is_numeric($date)) {
                    return date($format, (int)$date);
                }

                if (is_string($date)) {
                    $timestamp = strtotime($date);
                    return $timestamp !== false ? date($format, $timestamp) : '';
                }
            } catch (Throwable $e) {
                // Fehler protokollieren, aber leeren String zurückgeben
                $this->debugLog("Date formatting error", [
                    'input' => $date,
                    'format' => $format,
                    'error' => $e->getMessage()
                ]);
            }

            return '';
        });

        // Sichere JSON-Funktion
        $this->registerFunction('json', function (mixed $value, int $options = 0): string {
            $result = json_encode(
                $value,
                $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            if ($result === false) {
                throw new \RuntimeException('JSON encoding failed');
            }

            return $result;
        });

        // Raw-Output (unsicher, nur wenn nötig)
        $this->registerFunction('raw', function (mixed $value): string {
            return (string)$value;
        });
    }

    // Section-Management (unverändert, aber mit Debug-Logs)
    public function startSection(string $name): void
    {
        $this->debugLog("Starting section: {$name}");
        $this->currentSection = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if ($this->currentSection === null) {
            throw TemplateException::sectionNotStarted('unknown');
        }

        $content = ob_get_clean();
        $this->sections[$this->currentSection] = $content;
        $this->debugLog("Ended section: {$this->currentSection}", [
            'content_length' => strlen($content)
        ]);
        $this->currentSection = null;
    }

    public function yieldContent(string $name, string $default = ''): string
    {
        $content = $this->sections[$name] ?? $default;
        $this->debugLog("Yielding content for section: {$name}", [
            'has_content' => isset($this->sections[$name]),
            'content_length' => strlen($content)
        ]);
        return $content;
    }

    // Getters und weitere Methoden...
    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }
        return $this;
    }

    public function layout(?string $name = null): self
    {
        $this->layout = $name;
        return $this;
    }

    public function getLoader(): TemplateLoaderInterface
    {
        return $this->loader;
    }

    public function clearCache(): bool
    {
        $result = $this->cache->clear();
        $this->debugLog("Cache cleared", ['success' => $result]);
        return $result;
    }

    private function applyOutputFilters(string $content): string
    {
        foreach ($this->outputFilters as $filter) {
            $content = $filter($content);
        }
        return $content;
    }

    public function addOutputFilter(callable $filter): self
    {
        $this->outputFilters[] = $filter;
        return $this;
    }

    // Component-Management und Include-Methods bleiben unverändert...
    public function includeTemplate(string $name, array $data = []): string
    {
        return $this->renderTemplate($name, $data);
    }
}