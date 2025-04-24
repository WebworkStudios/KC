<?php


namespace Src\Config;

use Closure;
use ReflectionClass;
use ReflectionParameter;
use RuntimeException;

class Container
{
    private array $bindings = [];
    private array $instances = [];
    private array $aliases = [];

    /**
     * Register a binding in the container
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        // If no concrete type is given, use the abstract type
        $concrete = $concrete ?: $abstract;

        // If the concrete type is not a closure, make it one
        if (!$concrete instanceof Closure) {
            $concrete = function ($container) use ($concrete) {
                return $container->build($concrete);
            };
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');
    }

    /**
     * Register a shared binding in the container (singleton)
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register an existing instance in the container
     */
    public function instance(string $abstract, $instance): void
    {
        // Register an alias if the abstract is an interface and instance is a concrete class
        if (interface_exists($abstract) && is_object($instance)) {
            $this->alias(get_class($instance), $abstract);
        }

        $this->instances[$abstract] = $instance;
    }

    /**
     * Register an alias for an abstract type
     */
    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * Resolve a type from the container
     */
    public function resolve(string $abstract)
    {
        // If an alias exists for the abstract type, use that instead
        $abstract = $this->getAlias($abstract);

        // If an instance of the type exists, return it
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // If no binding exists, try to build it
        $concrete = $this->getConcrete($abstract);

        // If the type is registered as a singleton and we've already resolved it,
        // return the existing instance
        $object = $this->build($concrete);

        // If the binding is registered as a singleton, store it
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Get the concrete type for an abstract type
     */
    private function getConcrete(string $abstract)
    {
        // If no binding exists, return the abstract type
        if (!isset($this->bindings[$abstract])) {
            return $abstract;
        }

        return $this->bindings[$abstract]['concrete'];
    }

    /**
     * Determine if a binding is shared (singleton)
     */
    private function isShared(string $abstract): bool
    {
        return isset($this->bindings[$abstract]['shared']) &&
            $this->bindings[$abstract]['shared'] === true;
    }

    /**
     * Get the alias for an abstract type if it exists
     */
    private function getAlias(string $abstract): string
    {
        return isset($this->aliases[$abstract]) ? $this->getAlias($this->aliases[$abstract]) : $abstract;
    }

    /**
     * Instantiate a concrete instance of the type
     */
    public function build($concrete)
    {
        // If the concrete type is a closure, execute it and return the result
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        // Create a reflection class instance for the concrete type
        $reflector = new ReflectionClass($concrete);

        // If the class is not instantiable, throw an exception
        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Class [$concrete] is not instantiable");
        }

        // Get the constructor
        $constructor = $reflector->getConstructor();

        // If there is no constructor, just return a new instance
        if (is_null($constructor)) {
            return new $concrete();
        }

        // Get the constructor parameters
        $parameters = $constructor->getParameters();

        // Resolve all constructor parameters
        $dependencies = $this->resolveDependencies($parameters);

        // Create a new instance with the resolved dependencies
        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve all dependencies of a class constructor
     */
    private function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            // Get the parameter class type hint if it exists
            $type = $parameter->getType();

            // If no type hint or type is built-in (e.g., string, int), try to use default value
            if (!$type || $type->isBuiltin()) {
                // If the parameter has a default value, use it
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } elseif ($parameter->isOptional()) {
                    // If the parameter is optional but has no default, use null
                    $dependencies[] = null;
                } else {
                    throw new RuntimeException("Cannot resolve parameter [{$parameter->getName()}] without type hint");
                }
            } else {
                // If the parameter has a class type hint, resolve it from the container
                $dependencies[] = $this->resolve($type->getName());
            }
        }

        return $dependencies;
    }

    /**
     * Determine if a given type has been bound
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]) || isset($this->aliases[$abstract]);
    }

    /**
     * Get a registered instance
     */
    public function get(string $abstract)
    {
        return $this->resolve($abstract);
    }

    /**
     * "Make" an instance (alias for resolve)
     */
    public function make(string $abstract)
    {
        return $this->resolve($abstract);
    }

    /**
     * Call a method on an object with automatic dependency injection
     */
    public function call($callback, array $parameters = [])
    {
        if (is_string($callback) && strpos($callback, '@') !== false) {
            [$class, $method] = explode('@', $callback);
            $callback = [$this->resolve($class), $method];
        }

        $reflector = is_array($callback)
            ? new \ReflectionMethod($callback[0], $callback[1])
            : new \ReflectionFunction($callback);

        $dependencies = [];

        foreach ($reflector->getParameters() as $parameter) {
            // Check if the parameter is in the provided parameters
            if (array_key_exists($parameter->getName(), $parameters)) {
                $dependencies[] = $parameters[$parameter->getName()];
                continue;
            }

            // Try to resolve from the container
            $type = $parameter->getType();

            if (!$type || $type->isBuiltin()) {
                // If no type hint or built-in type, check for default value
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } elseif ($parameter->isOptional()) {
                    $dependencies[] = null;
                } else {
                    throw new RuntimeException("Cannot resolve parameter [{$parameter->getName()}] without type hint");
                }
            } else {
                // If class type hint, resolve from container
                $dependencies[] = $this->resolve($type->getName());
            }
        }

        return call_user_func_array($callback, $dependencies);
    }
}