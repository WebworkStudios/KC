<?php

/**
 * Advanced Dependency Injection Container
 *
 * Implementiert einen leistungsstarken DI-Container mit:
 * - Service Registry für einfaches Verwalten von Diensten
 * - Auto-Wiring für automatische Dependency Injection
 * - Property Hooks für reaktive Eigenschaften
 *
 * PHP Version 8.4
 */

declare(strict_types=1);

namespace Src\Container;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use WeakMap;

/**
 * Hauptklasse des Dependency Injection Containers
 */
class Container
{
    /** @var array<string, object|Closure> Gespeicherte Service-Instanzen und Factories */
    private array $services = [];

    /** @var array<string, string> Mapping von Interfaces zu konkreten Implementierungen */
    private array $bindings = [];

    /** @var array<string, array> Konfigurationsparameter für Services */
    private array $parameters = [];

    /** @var WeakMap Speichert Property-Proxies für Objekte */
    private WeakMap $propertyProxies;

    /**
     * Erstellt eine neue Container-Instanz
     */
    public function __construct()
    {
        $this->propertyProxies = new WeakMap();

        // Container als Service für sich selbst registrieren
        $this->services[self::class] = $this;
    }

    /**
     * Registriert einen Service im Container
     *
     * @param string $id Service-ID (typischerweise Klassenname)
     * @param object|Closure|string|null $service Service-Instanz, Factory oder Klassenname
     * @return self
     */
    public function register(string $id, object|string|null $service = null): self
    {
        if ($service === null) {
            $service = $id;
        }

        if (is_string($service) && class_exists($service)) {
            // Speichern als lazy-loading Factory
            $this->services[$id] = fn() => $this->createInstance($service);
        } else {
            $this->services[$id] = $service;
        }

        return $this;
    }

    /**
     * Bindet ein Interface an eine konkrete Implementierung
     *
     * @param string $interface Interface-Name
     * @param string $implementation Implementierungs-Klassenname
     * @return self
     */
    public function bind(string $interface, string $implementation): self
    {
        $this->bindings[$interface] = $implementation;
        return $this;
    }

    /**
     * Setzt Parameter für einen Service
     *
     * @param string $id Service-ID
     * @param array $parameters Parameter als assoziatives Array
     * @return self
     */
    public function setParameters(string $id, array $parameters): self
    {
        $this->parameters[$id] = $parameters;
        return $this;
    }

    /**
     * Holt einen Service aus dem Container
     *
     * @param string $id Service-ID
     * @return object Die Service-Instanz
     * @throws InvalidArgumentException Wenn der Service nicht gefunden wurde
     */
    public function get(string $id): object
    {
        // Prüfen ob Interface auf Implementierung gemappt ist
        if (isset($this->bindings[$id])) {
            $id = $this->bindings[$id];
        }

        // Prüfen ob Service bereits existiert
        if (isset($this->services[$id])) {
            $service = $this->services[$id];

            // Falls es eine Factory ist, ausführen und Ergebnis cachen
            if ($service instanceof Closure) {
                $service = $service();
                $this->services[$id] = $service;
            }

            return $service;
        }

        // Versuchen, den Service automatisch zu erstellen
        if (class_exists($id)) {
            $instance = $this->createInstance($id);
            $this->services[$id] = $instance;
            return $instance;
        }

        throw new InvalidArgumentException("Service nicht gefunden: $id");
    }

    /**
     * Überprüft, ob ein Service im Container registriert ist
     *
     * @param string $id Service-ID
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    /**
     * Erzeugt eine Instanz einer Klasse mit Auto-Wiring
     *
     * @param string $className Name der zu instanziierenden Klasse
     * @return object Die erzeugte Instanz
     * @throws RuntimeException Bei Problemen mit der Instanziierung
     */
    private function createInstance(string $className): object
    {
        $reflClass = new ReflectionClass($className);

        // Prüfen ob Klasse instanziierbar ist
        if (!$reflClass->isInstantiable()) {
            throw new RuntimeException("Klasse $className ist nicht instanziierbar");
        }

        // Konstruktor-Parameter auflösen
        $constructor = $reflClass->getConstructor();
        $dependencies = [];

        if ($constructor !== null) {
            $parameters = $constructor->getParameters();
            $dependencies = $this->resolveDependencies($className, $parameters);
        }

        // Instanz erstellen
        $instance = $reflClass->newInstanceArgs($dependencies);

        // Property Injection durchführen
        $this->injectProperties($instance);

        // Property Hooks einrichten
        $this->setupPropertyHooks($instance);

        return $instance;
    }

    /**
     * Löst Abhängigkeiten für einen Konstruktor oder eine Methode auf
     *
     * @param string $className Name der Klasse
     * @param array $parameters ReflectionParameter-Array
     * @return array Aufgelöste Parameter
     */
    private function resolveDependencies(string $className, array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $param) {
            /** @var ReflectionParameter $param */
            $dependencies[] = $this->resolveDependency($className, $param);
        }

        return $dependencies;
    }

    /**
     * Löst eine einzelne Abhängigkeit auf
     *
     * @param string $className Name der Klasse, zu der der Parameter gehört
     * @param ReflectionParameter $param Parameter-Reflektion
     * @return mixed Aufgelöster Parameter-Wert
     * @throws RuntimeException Wenn die Abhängigkeit nicht aufgelöst werden kann
     */
    private function resolveDependency(string $className, ReflectionParameter $param): mixed
    {
        $paramName = $param->getName();
        $paramType = $param->getType();

        // Service-Parameter prüfen (hohe Priorität)
        if (isset($this->parameters[$className][$paramName])) {
            return $this->parameters[$className][$paramName];
        }

        // Typ-basiertes Autowiring für Objekte
        if ($paramType instanceof ReflectionNamedType && !$paramType->isBuiltin()) {
            $typeName = $paramType->getName();

            if ($this->has($typeName)) {
                try {
                    return $this->get($typeName);
                } catch (\Throwable $e) {
                    // Falls Abhängigkeit nicht aufgelöst werden kann, weitermachen mit anderen Strategien
                }
            }
        }

        // Standardwert verwenden, falls verfügbar
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        // Wenn Parameter optional ist, null zurückgeben
        if ($param->allowsNull()) {
            return null;
        }

        // Keine Auflösungsstrategie gefunden
        throw new RuntimeException(
            "Konnte Parameter '$paramName' für Klasse '$className' nicht auflösen"
        );
    }

    /**
     * Führt Property Injection für eine Instanz durch
     *
     * @param object $instance Die zu injizierende Instanz
     * @return void
     */
    private function injectProperties(object $instance): void
    {
        $reflection = new ReflectionClass($instance);

        foreach ($reflection->getProperties() as $property) {
            // Nach Inject-Attribut suchen
            $attributes = $property->getAttributes(Inject::class);
            if (empty($attributes)) {
                continue;
            }

            /** @var Inject $inject */
            $inject = $attributes[0]->newInstance();
            $serviceId = $inject->serviceId;

            // Falls keine Service-ID angegeben, Typ verwenden
            if ($serviceId === null) {
                $type = $property->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                $serviceId = $type->getName();
            }

            // Eigenschaft zugänglich machen und Wert setzen
            $property->setAccessible(true);
            $property->setValue($instance, $this->get($serviceId));
        }
    }

    /**
     * Richtet Property Hooks für eine Instanz ein
     *
     * @param object $instance Die Instanz, für die Hooks eingerichtet werden sollen
     * @return void
     */
    private function setupPropertyHooks(object $instance): void
    {
        $reflection = new ReflectionClass($instance);
        $observableProperties = [];

        // Observable Properties sammeln
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Observable::class);
            if (empty($attributes)) {
                continue;
            }

            /** @var Observable $observable */
            $observable = $attributes[0]->newInstance();
            $observableProperties[$property->getName()] = $observable->callback;
        }

        // Wenn keine Observable Properties gefunden wurden, nichts zu tun
        if (empty($observableProperties)) {
            return;
        }

        // Proxy-Handler erstellen und in WeakMap speichern
        $propertyValues = [];
        $proxy = new class($instance, $observableProperties, $propertyValues) {
            private object $instance;
            private array $observableProperties;
            private array $propertyValues;

            public function __construct(object $instance, array $observableProperties, array &$propertyValues)
            {
                $this->instance = $instance;
                $this->observableProperties = $observableProperties;
                $this->propertyValues = &$propertyValues;

                // Initialwerte erfassen
                $reflection = new ReflectionClass($instance);
                foreach (array_keys($observableProperties) as $propName) {
                    $property = $reflection->getProperty($propName);
                    $property->setAccessible(true);
                    $this->propertyValues[$propName] = $property->getValue($instance);
                }
            }

            public function __set(string $name, mixed $value): void
            {
                // Nur Observable Properties überwachen
                if (!isset($this->observableProperties[$name])) {
                    $reflection = new ReflectionClass($this->instance);
                    if ($reflection->hasProperty($name)) {
                        $property = $reflection->getProperty($name);
                        $property->setAccessible(true);
                        $property->setValue($this->instance, $value);
                    }
                    return;
                }

                $oldValue = $this->propertyValues[$name] ?? null;

                // Wenn Wert gleich bleibt, nichts tun
                if ($oldValue === $value) {
                    return;
                }

                // Wert aktualisieren
                $reflection = new ReflectionClass($this->instance);
                $property = $reflection->getProperty($name);
                $property->setAccessible(true);
                $property->setValue($this->instance, $value);
                $this->propertyValues[$name] = $value;

                // Callback aufrufen, falls vorhanden
                $callback = $this->observableProperties[$name];
                if ($callback !== null && method_exists($this->instance, $callback)) {
                    $this->instance->{$callback}($oldValue, $value);
                }

                // Global PropertyHookAware-Interface aufrufen
                if ($this->instance instanceof PropertyHookAware) {
                    $this->instance->onPropertyChanged($name, $oldValue, $value);
                }
            }

            public function __get(string $name): mixed
            {
                $reflection = new ReflectionClass($this->instance);
                if ($reflection->hasProperty($name)) {
                    $property = $reflection->getProperty($name);
                    $property->setAccessible(true);
                    return $property->getValue($this->instance);
                }
                return null;
            }
        };

        $this->propertyProxies[$instance] = $proxy;
    }

    /**
     * Aktualisiert eine Eigenschaft einer Service-Instanz und löst dabei Hooks aus
     *
     * @param object $instance Die Instanz, deren Eigenschaft aktualisiert wird
     * @param string $property Name der Eigenschaft
     * @param mixed $value Neuer Wert
     * @return bool True, wenn der Wert geändert wurde
     */
    public function updateProperty(object $instance, string $property, mixed $value): bool
    {
        // Prüfen, ob für diese Instanz ein Proxy existiert
        if (!isset($this->propertyProxies[$instance])) {
            // Keine Observable Properties für diese Instanz
            $reflection = new ReflectionClass($instance);
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $oldValue = $prop->getValue($instance);

                if ($oldValue !== $value) {
                    $prop->setValue($instance, $value);
                    return true;
                }
            }
            return false;
        }

        // Proxy verwenden, um den Wert zu setzen und Hooks auszulösen
        $proxy = $this->propertyProxies[$instance];
        $oldValue = $proxy->{$property};

        if ($oldValue !== $value) {
            $proxy->{$property} = $value;
            return true;
        }

        return false;
    }
}