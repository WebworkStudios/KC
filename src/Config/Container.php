<?php

declare(strict_types=1);

namespace Src\Config;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionParameter;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionUnionType;
use ReflectionIntersectionType;
use Src\Config\Exceptions\CircularDependencyException;
use Src\Config\Exceptions\ContainerException;
use Src\Config\Exceptions\NotFoundException;
use Src\Config\Exceptions\UnresolvableParameterException;

class Container implements ContainerInterface
{
    /**
     * Container bindings
     *
     * @var array<string, array{concrete: Closure, shared: bool}>
     */
    private array $bindings = [];

    /**
     * Resolved instances
     *
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Abstract aliases
     *
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * Resolution stack for detecting circular dependencies
     *
     * @var string[]
     */
    private array $resolutionStack = [];

    /**
     * Cache for reflection objects
     *
     * @var array<string, ReflectionClass>
     */
    private array $reflectionCache = [];

    /**
     * Register a binding in the container
     */
    public function bind(string $abstract, mixed $concrete = null, bool $shared = false): void
    {
        // If no concrete type is given, use the abstract type
        $concrete = $concrete ?: $abstract;

        // If the concrete type is not a closure, make it one
        if (!$concrete instanceof Closure) {
            $concrete = function (Container $container) use ($concrete): mixed {
                return $container->build($concrete);
            };
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');
    }

    /**
     * Register a shared binding in the container (singleton)
     */
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register an existing instance in the container
     */
    public function instance(string $abstract, mixed $instance): void
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
        if ($abstract === $alias) {
            throw new ContainerException("Cannot alias [{$abstract}] to itself.");
        }

        $this->aliases[$alias] = $abstract;
    }

    /**
     * Resolve a type from the container
     *
     * @throws CircularDependencyException
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function resolve(string $abstract): mixed
    {
        // Check for circular dependencies
        if (in_array($abstract, $this->resolutionStack, true)) {
            throw new CircularDependencyException(
                "Circular dependency detected: " . implode(' -> ', $this->resolutionStack) . " -> {$abstract}"
            );
        }

        // Add to the resolution stack
        $this->resolutionStack[] = $abstract;

        try {
            // If an alias exists for the abstract type, use that instead
            $abstract = $this->getAlias($abstract);

            // If an instance of the type exists, return it
            if (isset($this->instances[$abstract])) {
                return $this->instances[$abstract];
            }

            // If no binding exists, try to build it
            $concrete = $this->getConcrete($abstract);

            // Build the concrete instance
            $object = $this->build($concrete);

            // If the binding is registered as a singleton, store it
            if ($this->isShared($abstract)) {
                $this->instances[$abstract] = $object;
            }

            return $object;
        } finally {
            // Always remove from resolution stack after resolution
            array_pop($this->resolutionStack);
        }
    }

    /**
     * Get the concrete type for an abstract type
     */
    private function getConcrete(string $abstract): mixed
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
        if (!isset($this->aliases[$abstract])) {
            return $abstract;
        }

        // Check for circular references in aliases
        if ($this->aliases[$abstract] === $abstract) {
            throw new ContainerException("Circular reference found in alias: {$abstract}");
        }

        return $this->getAlias($this->aliases[$abstract]);
    }

    /**
     * Instantiate a concrete instance of the type
     *
     * @throws ContainerException
     */
    public function build(mixed $concrete): mixed
    {
        // If the concrete type is a closure, execute it and return the result
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        // Create a reflection class instance for the concrete type
        $reflector = $this->getReflector($concrete);

        // If the class is not instantiable, throw an exception
        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Class [{$concrete}] is not instantiable");
        }

        // Get the constructor
        $constructor = $reflector->getConstructor();

        // If there is no constructor, just return a new instance
        if ($constructor === null) {
            return $reflector->newInstance();
        }

        // Get the constructor parameters
        $parameters = $constructor->getParameters();

        // Resolve all constructor parameters
        $dependencies = $this->resolveDependencies($parameters);

        // Create a new instance with the resolved dependencies
        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Get a reflection class instance for a concrete type
     *
     * @throws ContainerException
     */
    private function getReflector(string $concrete): ReflectionClass
    {
        if (!isset($this->reflectionCache[$concrete])) {
            try {
                $this->reflectionCache[$concrete] = new ReflectionClass($concrete);
            } catch (\ReflectionException $e) {
                throw new ContainerException("Error getting ReflectionClass for [{$concrete}]: " . $e->getMessage());
            }
        }

        return $this->reflectionCache[$concrete];
    }

    /**
     * Resolve all dependencies of a class constructor
     *
     * @param ReflectionParameter[] $parameters
     * @return array<mixed>
     * @throws UnresolvableParameterException
     */
    private function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            // Get the parameter type hint
            $type = $parameter->getType();

            // Handle different types of parameter type hints
            if ($type === null) {
                // If no type hint, try to use default value
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } elseif ($parameter->isOptional()) {
                    // If the parameter is optional but has no default, use null
                    $dependencies[] = null;
                } else {
                    throw new UnresolvableParameterException(
                        "Cannot resolve parameter [{$parameter->getName()}] without type hint"
                    );
                }
            } elseif ($type->isBuiltin()) {
                // Built-in types like string, int, etc.
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } elseif ($parameter->isOptional()) {
                    $dependencies[] = null;
                } else {
                    throw new UnresolvableParameterException(
                        "Cannot resolve built-in type parameter [{$parameter->getName()}] without default value"
                    );
                }
            } elseif ($type instanceof ReflectionUnionType) {
                // Handle union types (PHP 8.0+)
                $resolved = false;
                $exceptions = [];

                foreach ($type->getTypes() as $unionType) {
                    if ($unionType->isBuiltin()) {
                        continue;
                    }

                    try {
                        $dependencies[] = $this->resolve($unionType->getName());
                        $resolved = true;
                        break;
                    } catch (\Exception $e) {
                        $exceptions[] = $e->getMessage();
                    }
                }

                if (!$resolved) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                    } elseif ($parameter->isOptional()) {
                        $dependencies[] = null;
                    } else {
                        throw new UnresolvableParameterException(
                            "Cannot resolve union type parameter [{$parameter->getName()}]: " . implode(', ', $exceptions)
                        );
                    }
                }
            } elseif ($type instanceof ReflectionIntersectionType) {
                // Handle intersection types (PHP 8.1+)
                throw new UnresolvableParameterException(
                    "Cannot resolve intersection type parameter [{$parameter->getName()}]. Intersection types are not supported for automatic resolution."
                );
            } else {
                // Class or interface type
                try {
                    $dependencies[] = $this->resolve($type->getName());
                } catch (\Exception $e) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                    } elseif ($parameter->isOptional()) {
                        $dependencies[] = null;
                    } else {
                        throw new UnresolvableParameterException(
                            "Cannot resolve class type parameter [{$parameter->getName()}]: " . $e->getMessage()
                        );
                    }
                }
            }
        }

        return $dependencies;
    }

    /**
     * Determine if a given type has been bound
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]) || isset($this->aliases[$id]);
    }

    /**
     * Get a registered instance
     *
     * @throws NotFoundException
     */
    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new NotFoundException("No binding found for [{$id}]");
        }

        return $this->resolve($id);
    }

    /**
     * "Make" an instance (alias for resolve)
     */
    public function make(string $abstract): mixed
    {
        return $this->resolve($abstract);
    }

    /**
     * Call a method on an object with automatic dependency injection
     *
     * @throws ContainerException
     */
    public function call(callable|array|string $callback, array $parameters = []): mixed
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            [$class, $method] = explode('@', $callback, 2);
            $callback = [$this->resolve($class), $method];
        }

        try {
            $reflector = is_array($callback)
                ? new ReflectionMethod($callback[0], $callback[1])
                : new ReflectionFunction($callback);
        } catch (\ReflectionException $e) {
            throw new ContainerException("Error creating reflection for callback: " . $e->getMessage());
        }

        $callParameters = $reflector->getParameters();
        $dependencies = [];

        foreach ($callParameters as $parameter) {
            // Check if the parameter is in the provided parameters by name
            if (array_key_exists($parameter->getName(), $parameters)) {
                $dependencies[] = $parameters[$parameter->getName()];
                continue;
            }

            // Try to resolve from the container
            $type = $parameter->getType();

            if ($type === null || $type->isBuiltin()) {
                // If no type hint or built-in type, check for default value
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } elseif ($parameter->isOptional()) {
                    $dependencies[] = null;
                } else {
                    throw new UnresolvableParameterException(
                        "Cannot resolve parameter [{$parameter->getName()}] for callback without type hint"
                    );
                }
            } elseif ($type instanceof ReflectionUnionType) {
                // Handle union types
                $resolved = false;
                foreach ($type->getTypes() as $unionType) {
                    if ($unionType->isBuiltin()) {
                        continue;
                    }

                    try {
                        $dependencies[] = $this->resolve($unionType->getName());
                        $resolved = true;
                        break;
                    } catch (\Exception $e) {
                        // Try next type
                    }
                }

                if (!$resolved) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                    } elseif ($parameter->isOptional()) {
                        $dependencies[] = null;
                    } else {
                        throw new UnresolvableParameterException(
                            "Cannot resolve union type parameter [{$parameter->getName()}] for callback"
                        );
                    }
                }
            } else {
                // If class type hint, resolve from container
                try {
                    $dependencies[] = $this->resolve($type->getName());
                } catch (\Exception $e) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                    } elseif ($parameter->isOptional()) {
                        $dependencies[] = null;
                    } else {
                        throw new UnresolvableParameterException(
                            "Cannot resolve parameter [{$parameter->getName()}] for callback: " . $e->getMessage()
                        );
                    }
                }
            }
        }

        return call_user_func_array($callback, $dependencies);
    }

    /**
     * Clear a previously resolved instance
     */
    public function forgetInstance(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }

    /**
     * Clear all resolved instances
     */
    public function forgetAllInstances(): void
    {
        $this->instances = [];
    }

    /**
     * Clear reflection cache
     */
    public function clearReflectionCache(): void
    {
        $this->reflectionCache = [];
    }

    /**
     * Clear all container bindings
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->reflectionCache = [];
    }
}