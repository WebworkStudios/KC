<?php
<?php

namespace Src\Routing;

use Src\Config\AppConfig;

class Router
{
    private array $routes = [];
    private array $namedRoutes = [];
    private array $groupStack = [];
    private ?string $fallbackHandler = null;
    private ?string $cacheFile = null;
    private bool $cacheEnabled = false;

    public function __construct(private AppConfig $config)
    {
        $this->cacheEnabled = $config->get('router.cache_enabled', false);
        $this->cacheFile = $config->get('router.cache_file');

        if ($this->cacheEnabled && file_exists($this->cacheFile)) {
            $this->routes = require $this->cacheFile;
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
     * Add a route to the collection
     */
    private function addRoute(string $method, string $uri, string $action, ?string $name = null): self
    {
        $uri = $this->getGroupedUri($uri);

        $route = [
            'method' => $method,
            'uri' => $uri,
            'action' => $action,
            'parameters' => $this->extractParameters($uri)
        ];

        $this->routes[] = $route;

        if ($name !== null) {
            $this->namedRoutes[$name] = $route;
        }

        return $this;
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
        $this->fallbackHandler = $action;
    }

    /**
     * Get a route by name
     */
    public function getByName(string $name): ?array
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * Generate a URL for a named route
     */
    public function url(string $name, array $parameters = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route with name '$name' not found");
        }

        $uri = $this->namedRoutes[$name]['uri'];

        foreach ($parameters as $key => $value) {
            $uri = str_replace("{{$key}}", $value, $uri);
        }

        // Remove any remaining placeholders
        $uri = preg_replace('/{[^}]+}/', '', $uri);

        return $uri;
    }

    /**
     * Match the current request with a registered route
     */
    public function match(string $method, string $uri): array
    {
        $uri = trim($uri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            $pattern = $this->compileRoutePattern($route['uri']);

            if (preg_match($pattern, $uri, $matches)) {
                $parameters = $this->extractMatchedParameters($route, $matches);

                return [
                    'action' => $route['action'],
                    'parameters' => $parameters
                ];
            }
        }

        if ($this->fallbackHandler) {
            return [
                'action' => $this->fallbackHandler,
                'parameters' => []
            ];
        }

        throw new \RuntimeException("No route found for $method $uri");
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
            $parameters[$name] = $matches[$index] ?? null;
        }

        return $parameters;
    }

    /**
     * Cache the current routes
     */
    public function cacheRoutes(): bool
    {
        if (!$this->cacheEnabled || !$this->cacheFile) {
            return false;
        }

        $content = '<?php return ' . var_export($this->routes, true) . ';';

        return file_put_contents($this->cacheFile, $content) !== false;
    }

    /**
     * Clear the route cache
     */
    public function clearCache(): bool
    {
        if (!$this->cacheFile || !file_exists($this->cacheFile)) {
            return true;
        }

        return unlink($this->cacheFile);
    }
}