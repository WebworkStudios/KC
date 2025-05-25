<?php

declare(strict_types=1);

namespace Src\View;

use Src\Http\Response;
use Src\Http\Router;
use Src\Log\LoggerInterface;
use Src\Log\NullLogger;
use Src\View\Cache\FilesystemTemplateCache;
use Src\View\Cache\TemplateCacheInterface;
use Src\View\Compiler\TemplateCompiler;
use Src\View\Exception\TemplateException;
use Src\View\Functions\DefaultFunctions;
use Src\View\Loader\FilesystemTemplateLoader;
use Src\View\Loader\TemplateLoaderInterface;
use Throwable;

/**
 * Stark verbesserte ViewFactory
 *
 * Kritische Verbesserungen:
 * - Korrigierte TemplateEngine-Parameter-Reihenfolge
 * - Bessere Fehlerbehandlung
 * - Performance-Optimierungen
 * - Erweiterte Debug-Funktionen
 * - Template-Existenz-Cache
 */
class ViewFactory
{
    private TemplateEngine $engine;

    /** @var array<string, mixed> */
    private array $shared = [];

    private LoggerInterface $logger;
    private ?Router $router = null;

    /** @var array<string, bool> Cache für Template-Existenz-Prüfungen */
    private array $templateExistsCache = [];

    /** @var bool Debug-Modus */
    private bool $debugMode = false;

    /** @var array Performance-Metriken */
    private array $metrics = [
        'renders' => 0,
        'total_time' => 0.0,
        'cache_hits' => 0,
        'cache_misses' => 0
    ];

    public function __construct(TemplateEngine $engine, ?LoggerInterface $logger = null)
    {
        $this->engine = $engine;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Erstellt eine ViewFactory mit korrigierter auto-Konfiguration
     */
    public static function create(
        string $templateDir,
        string $cacheDir,
        bool $useCache = true,
        ?LoggerInterface $logger = null
    ): self {
        $logger = $logger ?? new NullLogger();

        try {
            $loader = new FilesystemTemplateLoader($templateDir);
            $compiler = new TemplateCompiler();
            $cache = new FilesystemTemplateCache($cacheDir, $useCache);

            // KORRIGIERTE Parameter-Reihenfolge: loader, compiler, cache
            $engine = new TemplateEngine($loader, $compiler, $cache);

            $logger->debug('ViewFactory created with corrected TemplateEngine parameter order', [
                'templateDir' => $templateDir,
                'cacheDir' => $cacheDir,
                'useCache' => $useCache
            ]);

            $factory = new self($engine, $logger);
            $factory->registerDefaultFunctions();

            return $factory;

        } catch (Throwable $e) {
            $logger->error('Failed to create ViewFactory', [
                'error' => $e->getMessage(),
                'templateDir' => $templateDir,
                'cacheDir' => $cacheDir
            ]);
            throw new TemplateException(
                "Failed to create ViewFactory: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Setzt den Debug-Modus
     */
    public function setDebugMode(bool $enabled): self
    {
        $this->debugMode = $enabled;
        $this->engine->setDebugMode($enabled);
        return $this;
    }

    /**
     * Setzt den Router für URL-Generierung
     */
    public function setRouter(Router $router): self
    {
        $this->router = $router;

        // DefaultFunctions mit Router neu registrieren
        $defaultFunctions = new DefaultFunctions($router);
        $this->engine->registerFunctionProvider($defaultFunctions);

        $this->logger->debug('Router set in ViewFactory');
        return $this;
    }

    /**
     * Erstellt eine View mit verbesserter Fehlerbehandlung
     */
    public function make(string $template, array $data = []): View
    {
        $startTime = microtime(true);

        try {
            // Template-Existenz prüfen (mit Cache)
            if (!$this->templateExists($template)) {
                throw TemplateException::templateNotFound($template);
            }

            // Globale Daten mit lokalen Daten zusammenführen
            $mergedData = $this->mergeData($data);

            $this->logger->debug('Creating view', [
                'template' => $template,
                'dataKeys' => array_keys($mergedData),
                'sharedDataKeys' => array_keys($this->shared)
            ]);

            $view = new View($this->engine, $template, $mergedData);

            // Metriken aktualisieren
            $this->metrics['renders']++;
            $this->metrics['total_time'] += (microtime(true) - $startTime) * 1000;

            return $view;

        } catch (Throwable $e) {
            $this->logger->error('Failed to create view', [
                'template' => $template,
                'error' => $e->getMessage(),
                'trace' => $this->debugMode ? $e->getTraceAsString() : null
            ]);
            throw $e;
        }
    }

    /**
     * Rendert ein Template direkt mit verbesserter Performance
     */
    public function render(
        string $template,
        array $data = [],
        int $status = 200,
        array $headers = []
    ): Response {
        $startTime = microtime(true);

        try {
            $view = $this->make($template, $data);
            $response = $view->toResponse($status, $headers);

            $renderTime = (microtime(true) - $startTime) * 1000;

            $this->logger->debug('Template rendered successfully', [
                'template' => $template,
                'status' => $status,
                'render_time_ms' => round($renderTime, 2),
                'response_size' => strlen($response->getContent())
            ]);

            return $response;

        } catch (Throwable $e) {
            $this->logger->error('Template rendering failed', [
                'template' => $template,
                'error' => $e->getMessage(),
                'render_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            throw $e;
        }
    }

    /**
     * Verbesserte Template-Existenz-Prüfung mit Cache
     */
    public function templateExists(string $template): bool
    {
        // Cache prüfen
        if (isset($this->templateExistsCache[$template])) {
            $this->metrics['cache_hits']++;
            return $this->templateExistsCache[$template];
        }

        $this->metrics['cache_misses']++;

        try {
            $exists = $this->engine->getLoader()->exists($template);

            // Cache aktualisieren (begrenzte Größe)
            if (count($this->templateExistsCache) >= 500) {
                // Älteste Einträge entfernen
                $this->templateExistsCache = array_slice($this->templateExistsCache, -400, null, true);
            }

            $this->templateExistsCache[$template] = $exists;
            return $exists;

        } catch (Throwable $e) {
            $this->logger->error("Error checking template existence: " . $e->getMessage(), [
                'template' => $template
            ]);
            return false;
        }
    }

    /**
     * Führt globale Daten mit lokalen Daten intelligent zusammen
     */
    private function mergeData(array $localData): array
    {
        // Einfache Zusammenführung für primitive Werte
        $mergedData = $this->shared;

        foreach ($localData as $key => $value) {
            if (isset($mergedData[$key]) && is_array($mergedData[$key]) && is_array($value)) {
                // Arrays rekursiv zusammenführen
                $mergedData[$key] = array_merge($mergedData[$key], $value);
            } else {
                // Lokale Daten haben Priorität
                $mergedData[$key] = $value;
            }
        }

        return $mergedData;
    }

    /**
     * Rendert einen Template-String
     */
    public function renderString(string $source, array $data = []): string
    {
        $mergedData = $this->mergeData($data);
        return $this->engine->renderString($source, $mergedData);
    }

    /**
     * Erstellt eine JSON-Response mit besserer Fehlerbehandlung
     */
    public function json(mixed $data, int $status = 200, int $options = 0): Response
    {
        try {
            $json = json_encode(
                $data,
                $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

            $response = new Response($json, $status);
            $response->setHeader('Content-Type', 'application/json; charset=UTF-8');

            return $response;

        } catch (\JsonException $e) {
            $this->logger->error('JSON encoding failed', [
                'error' => $e->getMessage(),
                'data_type' => gettype($data)
            ]);

            throw new TemplateException('Failed to encode data as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Registriert globale Daten für alle Views
     */
    public function share(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->shared = array_merge($this->shared, $key);
            $this->logger->debug('Shared multiple data items', [
                'keys' => array_keys($key)
            ]);
        } else {
            $this->shared[$key] = $value;
            $this->logger->debug("Shared data item: {$key}");
        }

        return $this;
    }

    /**
     * Registriert Standard-Funktionen
     */
    private function registerDefaultFunctions(): void
    {
        // Asset-Funktion
        $this->engine->registerFunction('asset', function (string $path): string {
            $path = ltrim($path, '/');

            $baseUrl = '';
            if (isset($_SERVER['HTTP_HOST'])) {
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
            }

            return $baseUrl . '/assets/' . $path;
        });

        // Verbesserte URL-Funktion
        $this->engine->registerFunction('url', function (string $route, array $params = []): string {
            if ($this->router !== null) {
                try {
                    return $this->router->url($route, $params);
                } catch (Throwable $e) {
                    $this->logger->warning("URL generation failed for route: {$route}", [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Fallback
            $url = '/' . trim($route, '/');
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            return $url;
        });

        // Debug-Funktion
        $this->engine->registerFunction('debug', function (mixed $value, string $label = ''): string {
            if (!$this->debugMode) {
                return '';
            }

            $output = $label ? "<strong>{$label}:</strong> " : '';
            $output .= '<pre>' . htmlspecialchars(print_r($value, true), ENT_QUOTES, 'UTF-8') . '</pre>';

            return $output;
        });

        // CSRF-Token-Funktion (falls verfügbar)
        $this->engine->registerFunction('csrf_token', function (): string {
            if (function_exists('csrf_token')) {
                return csrf_token();
            }

            // Einfacher Fallback
            if (!isset($_SESSION)) {
                session_start();
            }

            if (!isset($_SESSION['_token'])) {
                $_SESSION['_token'] = bin2hex(random_bytes(32));
            }

            return $_SESSION['_token'];
        });
    }

    /**
     * Registriert einen Function-Provider
     */
    public function registerFunctionProvider(FunctionProviderInterface $provider): self
    {
        $this->engine->registerFunctionProvider($provider);
        $this->logger->debug('Function provider registered', [
            'provider' => get_class($provider)
        ]);
        return $this;
    }

    /**
     * Registriert eine einzelne Hilfsfunktion
     */
    public function registerFunction(string $name, callable $callback): self
    {
        $this->engine->registerFunction($name, $callback);
        $this->logger->debug("Function registered: {$name}");
        return $this;
    }

    /**
     * Fügt einen Output-Filter hinzu
     */
    public function addOutputFilter(callable $filter): self
    {
        $this->engine->addOutputFilter($filter);
        return $this;
    }

    /**
     * Setzt das Layout für nachfolgende Views
     */
    public function layout(?string $name = null): self
    {
        $this->engine->layout($name);
        return $this;
    }

    /**
     * Löscht den Template-Cache mit verbessertem Feedback
     */
    public function clearCache(): bool
    {
        $result = $this->engine->clearCache();

        // Auch lokale Caches leeren
        $this->templateExistsCache = [];
        $this->metrics = [
            'renders' => 0,
            'total_time' => 0.0,
            'cache_hits' => 0,
            'cache_misses' => 0
        ];

        if ($result) {
            $this->logger->info('All template caches cleared successfully');
        } else {
            $this->logger->error('Failed to clear template cache');
        }

        return $result;
    }

    /**
     * Löscht ein einzelnes Template aus dem Cache
     */
    public function clearTemplateCache(string $template): bool
    {
        $result = $this->engine->clearTemplateCache($template);

        // Aus lokalem Cache entfernen
        unset($this->templateExistsCache[$template]);

        if ($result) {
            $this->logger->info("Template '{$template}' cleared from cache");
        } else {
            $this->logger->error("Failed to clear template '{$template}' from cache");
        }

        return $result;
    }

    /**
     * Gibt Performance-Metriken zurück
     */
    public function getMetrics(): array
    {
        $metrics = $this->metrics;
        $metrics['average_render_time'] = $metrics['renders'] > 0
            ? round($metrics['total_time'] / $metrics['renders'], 2)
            : 0;
        $metrics['cache_hit_ratio'] = ($metrics['cache_hits'] + $metrics['cache_misses']) > 0
            ? round($metrics['cache_hits'] / ($metrics['cache_hits'] + $metrics['cache_misses']) * 100, 1)
            : 0;

        return $metrics;
    }

    /**
     * Gibt die Template-Engine zurück
     */
    public function getEngine(): TemplateEngine
    {
        return $this->engine;
    }

    /**
     * Erstellt eine Fehler-Response
     */
    public function error(int $code, string $message, array $data = []): Response
    {
        $errorData = array_merge([
            'code' => $code,
            'message' => $message,
            'timestamp' => time()
        ], $data);

        // Spezifische Fehler-Templates versuchen
        $templates = ["errors.{$code}", 'errors.default', 'error'];

        foreach ($templates as $template) {
            if ($this->templateExists($template)) {
                return $this->render($template, $errorData, $code);
            }
        }

        // JSON-Fallback
        return $this->json(['error' => $errorData], $code);
    }

    /**
     * Automatische View-Erkennung basierend auf Action
     */
    public function renderAction(object $action, array $data = []): Response
    {
        $className = get_class($action);
        $shortName = substr($className, strrpos($className, '\\') + 1);

        // "Action"-Suffix entfernen
        if (str_ends_with($shortName, 'Action')) {
            $shortName = substr($shortName, 0, -6);
        }

        // CamelCase zu kebab-case
        $viewName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1.$2', $shortName));

        $this->logger->debug('Auto-rendering action view', [
            'action' => $shortName,
            'view' => $viewName
        ]);

        // Fallback-Views definieren
        $viewOptions = [
            $viewName,
            "views.{$viewName}",
            'views.default'
        ];

        foreach ($viewOptions as $template) {
            if ($this->templateExists($template)) {
                return $this->render($template, $data);
            }
        }

        // Als letzter Ausweg JSON zurückgeben
        return $this->json($data);
    }
}