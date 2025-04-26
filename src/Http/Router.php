<?php


namespace Src\Http;

use Exception;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use Src\Container\Container;

/**
 * Router-Klasse für das Routing von HTTP-Anfragen zu Action-Klassen
 */
class Router
{
    /** @var array<string, array<string, array>> Registrierte Routen [method => [path => [action, name, middleware]]] */
    private array $routes = [];

    /** @var array<string, string> Named routes für URL-Generierung */
    private array $namedRoutes = [];

    /**
     * Erstellt einen neuen Router
     *
     * @param Container $container DI-Container für das Auflösen von Action-Klassen
     */
    public function __construct(
        private readonly Container $container
    )
    {
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
            if (DEBUG) {
                echo "Warnung: Actions-Verzeichnis nicht gefunden: {$directory}";
            }
            return $this;
        }

        try {
            $directoryIterator = new RecursiveDirectoryIterator($directory);
            $iterator = new RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relativeFilePath = substr($file->getPathname(), strlen($directory) + 1);
                    $relativeFilePath = str_replace(['/', '\\'], '\\', $relativeFilePath);
                    $className = $namespace . '\\' . substr($relativeFilePath, 0, -4); // Entferne .php

                    if (class_exists($className)) {
                        $this->registerAction($className);
                    }
                }
            }
        } catch (Exception $e) {
            if (DEBUG) {
                echo "Fehler beim Durchsuchen des Actions-Verzeichnisses: " . $e->getMessage();
            }
            return $this;
        }

        return $this;
    }

    /**
     * Registriert eine einzelne Action-Klasse
     *
     * @param string $actionClass Klassenname der Action
     * @return self
     */
    public function registerAction(string $actionClass): self
    {
        $reflectionClass = new ReflectionClass($actionClass);

        // Die __invoke-Methode suchen
        if (!$reflectionClass->hasMethod('__invoke')) {
            return $this;
        }

        $method = $reflectionClass->getMethod('__invoke');
        $attributes = $method->getAttributes(Route::class);

        foreach ($attributes as $attribute) {
            /** @var Route $route */
            $route = $attribute->newInstance();
            $this->addRoute($route, $actionClass);
        }

        return $this;
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
            }
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

        // Prüfen, ob eine exakte Route für diese Methode und diesen Pfad existiert
        if (isset($this->routes[$method][$path])) {
            return $this->executeAction($this->routes[$method][$path], $request, []);
        }

        // Dynamische Routen mit Parametern prüfen
        foreach ($this->routes[$method] ?? [] as $routePath => $routeData) {
            $params = $this->matchRoute($routePath, $path);

            if ($params !== null) {
                return $this->executeAction($routeData, $request, $params);
            }
        }

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

        // Middleware ausführen
        foreach ($routeData['middleware'] as $middlewareClass) {
            $middleware = $this->container->get($middlewareClass);
            $response = $middleware->process($request, fn($req) => null);

            // Wenn Middleware eine Response zurückgibt, diese direkt zurückgeben
            if ($response instanceof Response) {
                return $response;
            }
        }

        // Action ausführen
        $action = $this->container->get($routeData['action']);
        $response = $action($request);

        if (!$response instanceof Response) {
            throw new RuntimeException(
                "Action {$routeData['action']} muss eine Response zurückgeben"
            );
        }

        return $response;
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
        // Statischen Teil der Route von den Parametern trennen
        $pattern = preg_replace('/\{([^}]+)\}/', '(?<$1>[^/]+)', $routePath);
        $pattern = "#^{$pattern}$#";

        if (preg_match($pattern, $requestPath, $matches)) {
            // Numerische Matches entfernen
            $params = array_filter($matches, fn($key) => !is_numeric($key), ARRAY_FILTER_USE_KEY);
            return $params;
        }

        return null;
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
            throw new InvalidArgumentException("Named route '$name' not found");
        }

        $url = $this->namedRoutes[$name];

        // Parameter in URL einsetzen
        foreach ($params as $key => $value) {
            $url = str_replace("{{$key}}", (string)$value, $url);
        }

        return $url;
    }
}