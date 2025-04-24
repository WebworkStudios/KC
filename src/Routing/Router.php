<?php
declare(strict_types=1);
namespace Src\Routing;

use Src\Config\AppConfig;
use InvalidArgumentException;
use RuntimeException;

class Router
{
    private array $routes = [];
    private array $namedRoutes = [];
    private array $groupStack = [];
    private ?string $fallbackHandler = null;
    private ?string $cacheFile = null;
    private bool $cacheEnabled = false;
    private array $compiledRoutes = [];
    private array $methodIndex = [];

    public function __construct(private AppConfig $config)
    {
        $this->cacheEnabled = $config->get('router.cache_enabled', false);
        $this->cacheFile = $config->get('router.cache_file');

        if ($this->cacheEnabled && $this->cacheFile && file_exists($this->cacheFile)) {
            $cacheData = require $this->cacheFile;
            if (is_array($cacheData) && isset($cacheData['routes'], $cacheData['compiledRoutes'])) {
                $this->routes = $cacheData['routes'];
                $this->compiledRoutes = $cacheData['compiledRoutes'];
                $this->buildMethodIndex();
            } else {
                $this->routes = $cacheData;
                $this->preCompileRoutes();
            }
        }
    }

    /**
     * Register a GET route
     */
    public function get(string $uri, string $action, ?string $name = null): self
    {
        return $this->addRoute('GET', $uri, $action, $name);
    }

    /**
     * Register a POST route
     */
    public function post(string $uri, string $action, ?string $name = null): self
    {
        return $this->addRoute('POST', $uri, $action, $name);
    }

    /**
     * Register a PUT route
     */
    public function put(string $uri, string $action, ?string $name = null): self
    {
        return $this->addRoute('PUT', $uri, $action, $name);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $uri, string $action, ?string $name = null): self
    {
        return $this->addRoute('DELETE', $uri, $action, $name);
    }

    /**
     * Register a route with any HTTP method
     */
    public function any(string $uri, string $action, ?string $name = null): self
    {
        return $this->addRoute('ANY', $uri, $action, $name);
    }

    /**
     * Register a route for multiple HTTP methods
     */
    public function map(array $methods, string $uri, string $action, ?string $name = null): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $uri, $action, $name);
        }

        return $this;
    }

    /**
     * Add a route to the collection
     */
    private function addRoute(string $method, string $uri, string $action, ?string $name = null): self
    {
        $uri = $this->getGroupedUri($uri);

        // Action validieren
        $this->validateAction($action);

        $route = [
            'method' => $method,
            'uri' => $uri,
            'action' => $action,
            'parameters' => $this->extractParameters($uri)
        ];

        $routeId = count($this->routes);
        $this->routes[$routeId] = $route;
        $this->compiledRoutes[$routeId] = $this->compileRoutePattern($uri);

        if (!isset($this->methodIndex[$method])) {
            $this->methodIndex[$method] = [];
        }
        $this->methodIndex[$method][] = $routeId;

        if ($method === 'ANY') {
            $methods = ['GET', 'POST', 'PUT', 'DELETE'];
            foreach ($methods as $httpMethod) {
                if (!isset($this->methodIndex[$httpMethod])) {
                    $this->methodIndex[$httpMethod] = [];
                }
                $this->methodIndex[$httpMethod][] = $routeId;
            }
        }

        if ($name !== null) {
            $this->namedRoutes[$name] = $routeId;
        }

        return $this;
    }

    /**
     * Validate that the action is in the correct format
     *
     * @throws InvalidArgumentException if the action format is invalid
     */
    private function validateAction(string $action): void
    {
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $action) &&
            !class_exists($action)) {
            throw new InvalidArgumentException("Invalid action format: $action");
        }
    }

    /**
     * Extract parameters from route URI
     */
    private function extractParameters(string $uri): array
    {
        $parameters = [];

        if (preg_match_all('/{([^}]+)}/', $uri, $matches)) {
            foreach ($matches[1] as $match) {
                $param = $match;
                $pattern = null;

                // Handle custom regex patterns
                if (str_contains($match, ':')) {
                    [$param, $pattern] = explode(':', $match, 2);

                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $param)) {
                        throw new InvalidArgumentException("Invalid parameter name: $param in route: $uri");
                    }

                    try {
                        preg_match("/$pattern/", "");
                    } catch (\Exception $e) {
                        throw new InvalidArgumentException("Invalid regex pattern for parameter $param: $pattern");
                    }
                } else {
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $param)) {
                        throw new InvalidArgumentException("Invalid parameter name: $param in route: $uri");
                    }
                }

                $parameters[$param] = $pattern;
            }
        }

        return $parameters;
    }

    /**
     * Group routes with a common prefix
     */
    public function group(string $prefix, callable $callback): void
    {
        $this->groupStack[] = $prefix;

        call_user_func($callback, $this);

        array_pop($this->groupStack);
    }

    /**
     * Get URI with group prefix
     */
    private function getGroupedUri(string $uri): string
    {
        return (empty($this->groupStack) ? '' : implode('', $this->groupStack)) . '/' . ltrim($uri, '/');
    }

    /**
     * Set a fallback handler for when no routes match
     */
    public function fallback(string $action): void
    {
        $this->validateAction($action);
        $this->fallbackHandler = $action;
    }

    /**
     * Get a route by name
     */
    public function getByName(string $name): ?array
    {
        if (!isset($this->namedRoutes[$name])) {
            return null;
        }

        $routeId = $this->namedRoutes[$name];
        return $this->routes[$routeId] ?? null;
    }

    /**
     * Generate a URL for a named route
     *
     * @param string $name Route name
     * @param array $parameters Parameters to substitute in the route
     * @return string Generated URL
     * @throws InvalidArgumentException If route name is not found
     */
    public function url(string $name, array $parameters = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new InvalidArgumentException("Route with name '$name' not found");
        }

        $routeId = $this->namedRoutes[$name];
        $route = $this->routes[$routeId];
        $uri = $route['uri'];
        $requiredParams = array_keys($route['parameters']);

        // Validate all required parameters are provided
        foreach ($requiredParams as $param) {
            if (!isset($parameters[$param])) {
                throw new InvalidArgumentException("Missing required parameter '$param' for route '$name'");
            }
        }

        foreach ($parameters as $key => $value) {
            $pattern = "{{$key}}";
            if (str_contains($uri, $pattern)) {
                // Einfache Validierung und Sanitierung des Parameter-Werts
                $value = $this->sanitizeParameterValue((string)$value);
                $uri = str_replace($pattern, $value, $uri);
            }
        }

        // Remove any remaining placeholders
        $uri = preg_replace('/{[^}]+}/', '', $uri);

        return '/' . trim($uri, '/');
    }

    /**
     * Pre-compile all route patterns for better performance
     */
    private function preCompileRoutes(): void
    {
        foreach ($this->routes as $id => $route) {
            $this->compiledRoutes[$id] = $this->compileRoutePattern($route['uri']);
        }

        $this->buildMethodIndex();
    }

    /**
     * Build method index for faster route matching
     */
    private function buildMethodIndex(): void
    {
        $this->methodIndex = [];

        foreach ($this->routes as $id => $route) {
            $method = $route['method'];

            if (!isset($this->methodIndex[$method])) {
                $this->methodIndex[$method] = [];
            }
            $this->methodIndex[$method][] = $id;

            // ANY-Routen in alle Methoden-Indizes einfügen
            if ($method === 'ANY') {
                $methods = ['GET', 'POST', 'PUT', 'DELETE'];
                foreach ($methods as $httpMethod) {
                    if (!isset($this->methodIndex[$httpMethod])) {
                        $this->methodIndex[$httpMethod] = [];
                    }
                    $this->methodIndex[$httpMethod][] = $id;
                }
            }
        }
    }

    /**
     * Match the current request with a registered route
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return array Matched route action and parameters
     * @throws RuntimeException If no route matches
     */
    public function match(string $method, string $uri): array
    {
        $uri = trim($uri, '/');

        // Zuerst nur die Routen für die spezifische Methode prüfen
        $routeIds = $this->methodIndex[$method] ?? [];

        foreach ($routeIds as $id) {
            $route = $this->routes[$id];
            $pattern = $this->compiledRoutes[$id] ?? $this->compileRoutePattern($route['uri']);

            if (preg_match($pattern, $uri, $matches)) {
                $parameters = $this->extractMatchedParameters($route, $matches);

                return [
                    'action' => $route['action'],
                    'parameters' => $parameters
                ];
            }
        }

        // Fallback-Handler prüfen
        if ($this->fallbackHandler) {
            return [
                'action' => $this->fallbackHandler,
                'parameters' => []
            ];
        }

        throw new RuntimeException("No route found for $method $uri");
    }

    /**
     * Compile route URI to regex pattern
     */
    private function compileRoutePattern(string $uri): string
    {
        $uri = trim($uri, '/');

        // Replace parameters with regex patterns
        $uri = preg_replace_callback('/{([^}]+)}/', function($matches) {
            $param = $matches[1];

            // If parameter has custom pattern
            if (str_contains($param, ':')) {
                [$name, $pattern] = explode(':', $param, 2);
                return "($pattern)";
            }

            // Default pattern for parameters
            return '([^/]+)';
        }, $uri);

        return '#^' . $uri . '$#';
    }

    /**
     * Extract parameters from matched route
     */
    private function extractMatchedParameters(array $route, array $matches): array
    {
        $parameters = [];
        $paramNames = array_keys($route['parameters']);

        // Skip the first match (the full pattern match)
        array_shift($matches);

        foreach ($paramNames as $index => $name) {
            $value = $matches[$index] ?? null;
            if ($value !== null) {
                $value = $this->sanitizeParameterValue($value);
            }
            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /**
     * Sanitize route parameter values
     */
    private function sanitizeParameterValue(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Cache the current routes
     *
     * @return bool Success status
     */
    public function cacheRoutes(): bool
    {
        if (!$this->cacheEnabled || !$this->cacheFile) {
            return false;
        }

        $this->preCompileRoutes();

        $cacheData = [
            'routes' => $this->routes,
            'compiledRoutes' => $this->compiledRoutes
        ];

        $content = '<?php return ' . var_export($cacheData, true) . ';';

        $tempFile = $this->cacheFile . '.tmp.' . uniqid('', true);

        if (file_put_contents($tempFile, $content) === false) {
            return false;
        }

        $result = rename($tempFile, $this->cacheFile);

        if (!$result && file_exists($tempFile)) {
            unlink($tempFile);
        }

        return $result;
    }

    /**
     * Clear the route cache
     *
     * @return bool Success status
     */
    public function clearCache(): bool
    {
        if (!$this->cacheFile || !file_exists($this->cacheFile)) {
            return true;
        }

        return unlink($this->cacheFile);
    }
}