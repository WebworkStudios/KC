<?php

namespace Src\Http;

use Exception;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use Src\Container\Container;
use Src\Log\LoggerInterface;
use Throwable;

/**
 * Router-Klasse für das Routing von HTTP-Anfragen zu Action-Klassen
 */
class Router
{
    /** @var array<string, array<string, array>> Registrierte Routen [method => [path => [action, name, middleware]]] */
    private array $routes = [];

    /** @var array<string, string> Named routes für URL-Generierung */
    private array $namedRoutes = [];

    /** @var LoggerInterface Logger-Instanz */
    private readonly LoggerInterface $logger;

    /** @var array Kompilierte RegEx-Muster für Routen */
    private array $compiledRoutePatterns = [];

    /** @var array Parametertyp-Definitionen für Routen */
    private array $paramTypes = [];

    /** @var array Mapping zwischen Parametertypen und RegEx-Mustern */
    private array $typePatterns = [
        'int' => '[0-9]+',
        'float' => '[0-9]+(?:\.[0-9]+)?',
        'uuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        'date' => '\d{4}-\d{2}-\d{2}',
        'alpha' => '[a-zA-Z]+',
        'alphanumeric' => '[a-zA-Z0-9]+',
        'string' => '[^/]+' // Standard
    ];

    /**
     * Erstellt einen neuen Router
     *
     * @param Container $container DI-Container für das Auflösen von Action-Klassen
     * @param LoggerInterface|null $logger Logger für Router-Operationen
     */
    public function __construct(
        private readonly Container $container,
        ?LoggerInterface           $logger = null
    )
    {
        // Logger aus Container holen, falls nicht direkt übergeben
        $this->logger = $logger ?? $container->get(LoggerInterface::class);
    }

    /**
     * Registriert alle Action-Klassen mit Route-Attributen aus einem bestimmten Namespace
     *
     * @param string $namespace Namespace der Action-Klassen (z.B. 'App\\Actions')
     * @param string $directory Verzeichnis, in dem die Action-Klassen gesucht werden sollen
     * @return self
     */
    public function registerActionsFromDirectory(string $namespace, string $directory): self
    {
        $directory = rtrim($directory, '/\\');

        if (!is_dir($directory)) {
            $this->logger->warning("Actions-Verzeichnis nicht gefunden", [
                'directory' => $directory,
                'namespace' => $namespace
            ]);
            return $this;
        }

        try {
            $directoryIterator = new RecursiveDirectoryIterator($directory);
            $iterator = new RecursiveIteratorIterator($directoryIterator);
            $actionCount = 0;
            $routeCount = 0;

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relativeFilePath = substr($file->getPathname(), strlen($directory) + 1);
                    $relativeFilePath = str_replace(['/', '\\'], '\\', $relativeFilePath);
                    $className = $namespace . '\\' . substr($relativeFilePath, 0, -4); // Entferne .php

                    if (class_exists($className)) {
                        $actionCount++;
                        $newRoutes = $this->registerAction($className);
                        $routeCount += $newRoutes;
                    }
                }
            }

            $this->logger->info("Actions registriert", [
                'directory' => $directory,
                'namespace' => $namespace,
                'action_count' => $actionCount,
                'route_count' => $routeCount
            ]);
        } catch (Exception $e) {
            $this->logger->error("Fehler beim Durchsuchen des Actions-Verzeichnisses", [
                'directory' => $directory,
                'namespace' => $namespace,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this;
        }

        return $this;
    }

    /**
     * Registriert eine einzelne Action-Klasse
     *
     * @param string $actionClass Klassenname der Action
     * @return int Anzahl der registrierten Routen
     */
    public function registerAction(string $actionClass): int
    {
        $reflectionClass = new ReflectionClass($actionClass);
        $routeCount = 0;

        // Die __invoke-Methode suchen
        if (!$reflectionClass->hasMethod('__invoke')) {
            $this->logger->debug("Keine __invoke-Methode gefunden in Action", [
                'action' => $actionClass
            ]);
            return $routeCount;
        }

        $method = $reflectionClass->getMethod('__invoke');
        $attributes = $method->getAttributes(Route::class);

        foreach ($attributes as $attribute) {
            /** @var Route $route */
            $route = $attribute->newInstance();
            $this->addRoute($route, $actionClass);
            $routeCount++;

            $this->logger->debug("Route registriert", [
                'action' => $actionClass,
                'path' => $route->path,
                'methods' => is_array($route->methods) ? implode(',', $route->methods) : $route->methods,
                'name' => $route->name
            ]);
        }

        return $routeCount;
    }

    /**
     * Fügt eine Route hinzu
     *
     * @param Route $route Route-Attribut
     * @param string $actionClass Klassenname der Action
     * @return void
     */
    private function addRoute(Route $route, string $actionClass): void
    {
        $methods = is_array($route->methods) ? $route->methods : [$route->methods];

        // Parameter-Typen aus Route extrahieren
        $this->extractParameterTypes($route->path);

        foreach ($methods as $method) {
            $method = strtoupper($method);

            if (!isset($this->routes[$method])) {
                $this->routes[$method] = [];
            }

            $this->routes[$method][$route->path] = [
                'action' => $actionClass,
                'name' => $route->name,
                'middleware' => $route->middleware
            ];

            // Named Route registrieren, falls vorhanden
            if ($route->name !== null) {
                $this->namedRoutes[$route->name] = $route->path;
                $this->logger->debug("Named Route registriert", [
                    'name' => $route->name,
                    'path' => $route->path
                ]);
            }
        }
    }

    /**
     * Extrahiert Parameter-Typen aus einem Routenpfad (z.B. /users/{id:int}/profile)
     *
     * @param string $path Routenpfad
     * @return void
     */
    private function extractParameterTypes(string $path): void
    {
        $this->paramTypes[$path] = [];
        preg_match_all('/\{([^:}]+)(?::([^}]+))?}/', $path, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $paramName = $match[1];
            $paramType = $match[2] ?? 'string';

            if (!isset($this->typePatterns[$paramType])) {
                $this->logger->warning("Unbekannter Parametertyp in Route", [
                    'path' => $path,
                    'param' => $paramName,
                    'type' => $paramType
                ]);
                $paramType = 'string';
            }

            $this->paramTypes[$path][$paramName] = $paramType;
        }
    }

    /**
     * Dispatcht eine Request zu der passenden Action
     *
     * @param Request $request HTTP-Request
     * @return Response|null Response oder null, wenn keine Route gefunden wurde
     */
    public function dispatch(Request $request): ?Response
    {
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getPath());

        $this->logger->debug("Dispatching request", [
            'method' => $method,
            'path' => $path
        ]);

        // Prüfen, ob eine exakte Route für diese Methode und diesen Pfad existiert
        if (isset($this->routes[$method][$path])) {
            $routeData = $this->routes[$method][$path];
            $this->logger->debug("Exakte Route gefunden", [
                'action' => $routeData['action'],
                'name' => $routeData['name'] ?? 'unnamed'
            ]);
            return $this->executeAction($routeData, $request, []);
        }

        // Dynamische Routen mit Parametern prüfen
        foreach ($this->routes[$method] ?? [] as $routePath => $routeData) {
            $params = $this->matchRoute($routePath, $path);

            if ($params !== null) {
                $this->logger->debug("Dynamische Route gefunden", [
                    'route_path' => $routePath,
                    'action' => $routeData['action'],
                    'name' => $routeData['name'] ?? 'unnamed',
                    'params' => $params
                ]);

                // Parameter-Typen konvertieren
                if (isset($this->paramTypes[$routePath])) {
                    $params = $this->convertParameterTypes($params, $this->paramTypes[$routePath]);
                }

                return $this->executeAction($routeData, $request, $params);
            }
        }

        $this->logger->notice("Keine Route gefunden", [
            'method' => $method,
            'path' => $path
        ]);
        return null;
    }

    /**
     * Normalisiert einen Pfad (entfernt Trailing Slashes, etc.)
     *
     * @param string $path Zu normalisierender Pfad
     * @return string Normalisierter Pfad
     */
    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * Führt eine Action aus und gibt die Response zurück
     *
     * @param array $routeData Route-Daten [action, name, middleware]
     * @param Request $request HTTP-Request
     * @param array $params Route-Parameter
     * @return Response
     */
    private function executeAction(array $routeData, Request $request, array $params): Response
    {
        // Route-Parameter zum Request hinzufügen
        foreach ($params as $name => $value) {
            $request->setRouteParameter($name, $value);
        }

        $this->logger->debug("Executing action", [
            'action' => $routeData['action'],
            'route_params' => $params
        ]);

        // Middleware ausführen
        foreach ($routeData['middleware'] as $middlewareClass) {
            $this->logger->debug("Executing middleware", [
                'middleware' => $middlewareClass
            ]);

            $middleware = $this->container->get($middlewareClass);
            $response = $middleware->process($request, fn($req) => null);

            // Wenn Middleware eine Response zurückgibt, diese direkt zurückgeben
            if ($response instanceof Response) {
                $this->logger->debug("Middleware returned response", [
                    'middleware' => $middlewareClass,
                    'status' => $response->getStatus()
                ]);
                return $response;
            }
        }

        // Action ausführen
        try {
            $action = $this->container->get($routeData['action']);
            $response = $action($request);

            if (!$response instanceof Response) {
                $this->logger->error("Action returned invalid response type", [
                    'action' => $routeData['action'],
                    'type' => gettype($response)
                ]);
                throw new RuntimeException(
                    "Action {$routeData['action']} muss eine Response zurückgeben"
                );
            }

            $this->logger->debug("Action executed successfully", [
                'action' => $routeData['action'],
                'status' => $response->getStatus()
            ]);

            return $response;
        } catch (Throwable $e) {
            $this->logger->error("Error executing action", [
                'action' => $routeData['action'],
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Prüft, ob ein Pfad zu einer Route mit Parametern passt
     *
     * @param string $routePath Route-Pfad mit Parameter-Platzhaltern
     * @param string $requestPath Angefragter Pfad
     * @return array|null Parameter oder null, wenn keine Übereinstimmung
     */
    private function matchRoute(string $routePath, string $requestPath): ?array
    {
        $pattern = $this->getCompiledPattern($routePath);

        if (preg_match($pattern, $requestPath, $matches)) {
            // Numerische Matches entfernen
            $params = array_filter($matches, fn($key) => !is_numeric($key), ARRAY_FILTER_USE_KEY);
            return $params;
        }

        return null;
    }

    /**
     * Gibt ein kompiliertes RegEx-Muster für einen Routenpfad zurück (mit Caching)
     *
     * @param string $routePath Routenpfad
     * @return string Kompiliertes RegEx-Muster
     */
    private function getCompiledPattern(string $routePath): string
    {
        if (!isset($this->compiledRoutePatterns[$routePath])) {
            // Parameter mit Typen erkennen und ersetzen
            $pattern = preg_replace_callback('/\{([^:}]+)(?::([^}]+))?}/', function($matches) {
                $name = $matches[1];
                $type = $matches[2] ?? 'string';
                $typePattern = $this->typePatterns[$type] ?? $this->typePatterns['string'];

                return "(?<$name>$typePattern)";
            }, $routePath);

            $this->compiledRoutePatterns[$routePath] = "#^{$pattern}$#";
        }

        return $this->compiledRoutePatterns[$routePath];
    }

    /**
     * Konvertiert Parametertypen basierend auf Typdefinitionen
     *
     * @param array $params Extrahierte Parameter
     * @param array $paramTypes Parametertypen
     * @return array Konvertierte Parameter
     */
    private function convertParameterTypes(array $params, array $paramTypes): array
    {
        foreach ($params as $name => $value) {
            if (isset($paramTypes[$name])) {
                switch ($paramTypes[$name]) {
                    case 'int':
                        $params[$name] = (int)$value;
                        break;
                    case 'float':
                        $params[$name] = (float)$value;
                        break;
                    case 'bool':
                        $params[$name] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'date':
                        // Konvertiere zu DateTime, falls gültiges Datumsformat
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            try {
                                $params[$name] = new \DateTime($value);
                            } catch (\Exception $e) {
                                // Bei ungültigem Datum den String behalten
                            }
                        }
                        break;
                }
            }
        }

        return $params;
    }

    /**
     * Generiert eine URL für eine benannte Route
     *
     * @param string $name Name der Route
     * @param array $params Parameter für die Route
     * @return string URL
     * @throws InvalidArgumentException Wenn die Route nicht gefunden wurde
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            $this->logger->warning("Named route nicht gefunden", [
                'name' => $name
            ]);
            throw new InvalidArgumentException("Named route '$name' not found");
        }

        $url = $this->namedRoutes[$name];

        // Prüfen, ob alle erforderlichen Parameter vorhanden sind
        preg_match_all('/\{([^:}]+)(?::([^}]+))?}/', $url, $matches);
        $requiredParams = $matches[1];

        foreach ($requiredParams as $param) {
            if (!isset($params[$param])) {
                throw new InvalidArgumentException("Parameter '$param' fehlt für Route '$name'");
            }
        }

        // Parameter in URL einsetzen
        foreach ($params as $key => $value) {
            // Für DateTime-Objekte String-Repräsentation verwenden
            if ($value instanceof \DateTime) {
                $value = $value->format('Y-m-d');
            }

            $url = str_replace("{{$key}}", (string)$value, $url);
        }

        $this->logger->debug("URL generiert", [
            'name' => $name,
            'params' => $params,
            'url' => $url
        ]);

        return $url;
    }
}