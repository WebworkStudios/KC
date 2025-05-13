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

/**
 * Factory-Klasse für die Erstellung von Views
 */
class ViewFactory
{
    /**
     * Template-Engine
     *
     * @var TemplateEngine
     */
    private TemplateEngine $engine;

    /**
     * Globale View-Daten
     *
     * @var array<string, mixed>
     */
    private array $shared = [];

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Router
     *
     * @var Router|null
     */
    private ?Router $router = null;

    /**
     * Erstellt eine neue ViewFactory
     *
     * @param TemplateEngine $engine Template-Engine oder null für auto-konfigurierte Engine
     * @param LoggerInterface|null $logger Logger oder null für NullLogger
     */
    public function __construct(TemplateEngine $engine, ?LoggerInterface $logger = null)
    {
        $this->engine = $engine;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Erstellt eine neue ViewFactory mit automatischer Konfiguration
     *
     * @param string $templateDir Template-Verzeichnis
     * @param string $cacheDir Cache-Verzeichnis
     * @param bool $useCache Cache verwenden?
     * @param LoggerInterface|null $logger Logger oder null für NullLogger
     * @return self
     */
    public static function create(
        string $templateDir,
        string $cacheDir,
        bool $useCache = true,
        ?LoggerInterface $logger = null
    ): self {
        $logger = $logger ?? new NullLogger();

        $loader = new FilesystemTemplateLoader($templateDir);
        $cache = new FilesystemTemplateCache($cacheDir, $useCache);
        $compiler = new TemplateCompiler();
        $engine = new TemplateEngine($loader, $cache, $compiler);

        $logger->debug('ViewFactory created with auto-configured Template Engine', [
            'templateDir' => $templateDir,
            'cacheDir' => $cacheDir,
            'useCache' => $useCache
        ]);

        return new self($engine, $logger);
    }

    /**
     * Erstellt eine View
     *
     * @param string $template Template-Name
     * @param array<string, mixed> $data View-Daten
     * @return View View-Instanz
     */
    public function make(string $template, array $data = []): View
    {
        // Globale Daten mit lokalen Daten zusammenführen
        $mergedData = array_merge($this->shared, $data);

        $this->logger->debug('Creating view', [
            'template' => $template,
            'dataKeys' => array_keys($mergedData)
        ]);

        return new View($this->engine, $template, $mergedData);
    }

    /**
     * Rendert ein Template direkt
     *
     * @param string $template Template-Name
     * @param array<string, mixed> $data View-Daten
     * @param int $status HTTP-Statuscode
     * @param array<string, string> $headers HTTP-Header
     * @return Response HTTP-Response
     */
    public function render(
        string $template,
        array $data = [],
        int $status = 200,
        array $headers = []
    ): Response {
        return $this->make($template, $data)->toResponse($status, $headers);
    }

    /**
     * Rendert einen Template-String
     *
     * @param string $source Template-String
     * @param array<string, mixed> $data View-Daten
     * @return string Gerenderter Inhalt
     */
    public function renderString(string $source, array $data = []): string
    {
        $mergedData = array_merge($this->shared, $data);
        return $this->engine->renderString($source, $mergedData);
    }

    /**
     * Erstellt eine JSON-Response
     *
     * @param mixed $data JSON-Daten
     * @param int $status HTTP-Statuscode
     * @param int $options JSON-Optionen
     * @return Response HTTP-Response
     */
    public function json(mixed $data, int $status = 200, int $options = 0): Response
    {
        $json = json_encode($data, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new TemplateException('Failed to encode data as JSON: ' . json_last_error_msg());
        }

        return new Response($json, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * Registriert globale Daten für alle Views
     *
     * @param string|array<string, mixed> $key Schlüssel oder assoziatives Array
     * @param mixed $value Wert (wenn $key ein String ist)
     * @return self
     */
    public function share(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->shared = array_merge($this->shared, $key);
        } else {
            $this->shared[$key] = $value;
        }

        return $this;
    }

    /**
     * Registriert einen Function-Provider
     *
     * @param FunctionProviderInterface $provider Function-Provider
     * @return self
     */
    public function registerFunctionProvider(FunctionProviderInterface $provider): self
    {
        $this->engine->registerFunctionProvider($provider);
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
        $this->engine->registerFunction($name, $callback);
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
        $this->engine->addOutputFilter($filter);
        return $this;
    }

    /**
     * Setzt den Router für URL-Generierung
     *
     * @param Router $router Router
     * @return self
     */
    public function setRouter(Router $router): self
    {
        $this->router = $router;

        // DefaultFunctions mit Router registrieren
        $this->registerFunctionProvider(new DefaultFunctions($router));

        $this->logger->debug('Router set for URL generation');

        return $this;
    }

    /**
     * Setzt das Layout für nachfolgende Views
     *
     * @param string|null $name Layout-Name
     * @return self
     */
    public function layout(?string $name = null): self
    {
        $this->engine->layout($name);
        return $this;
    }

    /**
     * Löscht den Template-Cache
     *
     * @return bool True bei Erfolg
     */
    public function clearCache(): bool
    {
        $result = $this->engine->clearCache();

        if ($result) {
            $this->logger->info('Template cache cleared');
        } else {
            $this->logger->error('Failed to clear template cache');
        }

        return $result;
    }

    /**
     * Löscht ein Template aus dem Cache
     *
     * @param string $template Template-Name
     * @return bool True bei Erfolg
     */
    public function clearTemplateCache(string $template): bool
    {
        $result = $this->engine->clearTemplateCache($template);

        if ($result) {
            $this->logger->info("Template '{$template}' cleared from cache");
        } else {
            $this->logger->error("Failed to clear template '{$template}' from cache");
        }

        return $result;
    }

    /**
     * Gibt die Template-Engine zurück
     *
     * @return TemplateEngine
     */
    public function getEngine(): TemplateEngine
    {
        return $this->engine;
    }

    /**
     * Rendert einen Controller mit automatischer View-Erkennung
     *
     * @param object $controller Controller-Objekt
     * @param string $method Controller-Methode
     * @param array<string, mixed> $data View-Daten
     * @return Response HTTP-Response
     */
    public function renderController(object $controller, string $method, array $data = []): Response
    {
        // Controller-Name bestimmen
        $className = get_class($controller);
        $shortName = substr($className, strrpos($className, '\\') + 1);
        $controllerName = str_replace('Controller', '', $shortName);

        // View-Name generieren
        $viewName = strtolower($controllerName) . '.' . $method;

        $this->logger->debug('Auto-rendering controller view', [
            'controller' => $controllerName,
            'method' => $method,
            'view' => $viewName
        ]);

        return $this->render($viewName, $data);
    }

    /**
     * Erstellt eine View mit einem Fehler
     *
     * @param int $code HTTP-Statuscode
     * @param string $message Fehlermeldung
     * @param array<string, mixed> $data Zusätzliche Daten
     * @return Response HTTP-Response
     */
    public function error(int $code, string $message, array $data = []): Response
    {
        $errorData = array_merge([
            'code' => $code,
            'message' => $message
        ], $data);

        // Versuche eine spezifische Fehler-View zu verwenden
        $template = "errors.{$code}";

        // Generische Fehler-View als Fallback
        if (!$this->templateExists($template)) {
            $template = 'errors.default';

            // JSON-Response als letzter Fallback
            if (!$this->templateExists($template)) {
                return $this->json(['error' => $errorData], $code);
            }
        }

        return $this->render($template, $errorData, $code);
    }
}