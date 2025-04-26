<?php

/**
 * Advanced Dependency Injection Container
 *
 * Implementiert einen leistungsstarken DI-Container mit:
 * - Service Registry für einfaches Verwalten von Diensten
 * - Auto-Wiring für automatische Dependency Injection
 * - Property Hooks für reaktive Eigenschaften
 * - Logger-Unterstützung für Fehlerbehebung und Debugging
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
use Src\Log\LoggerInterface;
use Src\Log\NullLogger;
use Throwable;
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

    /** @var LoggerInterface Logger für Debugging und Fehlermeldungen */
    private LoggerInterface $logger;

    /** @var bool Aktiviert/deaktiviert ausführliches Logging */
    private bool $verboseLogging = false;

    /**
     * Erstellt eine neue Container-Instanz
     *
     * @param LoggerInterface|null $logger Optional: Logger für Debug-Informationen
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->propertyProxies = new WeakMap();

        // Logger setzen oder NullLogger als Fallback verwenden
        $this->logger = $logger ?? new NullLogger();

        // Container als Service für sich selbst registrieren
        $this->services[self::class] = $this;

        $this->logger->debug('Container initialisiert');
    }

    /**
     * Setzt den Logger für den Container
     *
     * @param LoggerInterface $logger Logger-Instanz
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->logger->debug('Logger für Container gesetzt');
        return $this;
    }

    /**
     * Aktiviert oder deaktiviert ausführliches Logging
     *
     * @param bool $verbose True für ausführliches Logging, false für minimales Logging
     * @return self
     */
    public function setVerboseLogging(bool $verbose): self
    {
        $this->verboseLogging = $verbose;
        $this->logger->debug('Verbose Logging ' . ($verbose ? 'aktiviert' : 'deaktiviert'));
        return $this;
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
            if ($this->verboseLogging) {
                $this->logger->debug("Service '{$id}' als lazy-loading Factory registriert mit Klasse '{$service}'");
            }
        } else {
            $this->services[$id] = $service;
            if ($this->verboseLogging) {
                $serviceType = is_object($service) ? get_class($service) : gettype($service);
                $this->logger->debug("Service '{$id}' direkt registriert mit Typ '{$serviceType}'");
            }
        }

        return $this;
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
        try {
            $reflClass = new ReflectionClass($className);

            // Prüfen ob Klasse instanziierbar ist
            if (!$reflClass->isInstantiable()) {
                $error = "Klasse $className ist nicht instanziierbar";
                $this->logger->error($error);
                throw new RuntimeException($error);
            }

            if ($this->verboseLogging) {
                $this->logger->debug("Erstelle Instanz von '{$className}'");
            }

            // Konstruktor-Parameter auflösen
            $constructor = $reflClass->getConstructor();
            $dependencies = [];

            if ($constructor !== null) {
                $parameters = $constructor->getParameters();
                if ($this->verboseLogging && count($parameters) > 0) {
                    $paramNames = array_map(fn($param) => $param->getName(), $parameters);
                    $this->logger->debug("Löse Konstruktor-Parameter auf für '{$className}': " . implode(', ', $paramNames));
                }

                $dependencies = $this->resolveDependencies($className, $parameters);
            }

            // Instanz erstellen
            $instance = $reflClass->newInstanceArgs($dependencies);

            if ($this->verboseLogging) {
                $this->logger->debug("Instanz von '{$className}' erfolgreich erstellt");
            }

            // Property Injection durchführen
            $this->injectProperties($instance);

            // Property Hooks einrichten
            $this->setupPropertyHooks($instance);

            return $instance;
        } catch (Throwable $e) {
            // Bei Fehler detaillierte Informationen loggen
            $this->logger->error("Fehler beim Erstellen der Instanz von '{$className}': " . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
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
            try {
                $dependencies[] = $this->resolveDependency($className, $param);

                if ($this->verboseLogging) {
                    $paramName = $param->getName();
                    $this->logger->debug("Parameter '{$paramName}' für '{$className}' erfolgreich aufgelöst");
                }
            } catch (Throwable $e) {
                $paramName = $param->getName();
                $this->logger->error("Fehler beim Auflösen des Parameters '{$paramName}' für '{$className}': " . $e->getMessage());
                throw $e;
            }
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
            if ($this->verboseLogging) {
                $this->logger->debug("Parameter '{$paramName}' für '{$className}' aus konfigurierten Parametern aufgelöst");
            }
            return $this->parameters[$className][$paramName];
        }

        // Typ-basiertes Autowiring für Objekte
        if ($paramType instanceof ReflectionNamedType && !$paramType->isBuiltin()) {
            $typeName = $paramType->getName();

            if ($this->has($typeName)) {
                try {
                    if ($this->verboseLogging) {
                        $this->logger->debug("Parameter '{$paramName}' für '{$className}' per Autowiring aufgelöst: '{$typeName}'");
                    }
                    return $this->get($typeName);
                } catch (Throwable $e) {
                    // Falls Abhängigkeit nicht aufgelöst werden kann, weitermachen mit anderen Strategien
                    $this->logger->warning("Autowiring für Parameter '{$paramName}' in '{$className}' fehlgeschlagen: " . $e->getMessage());
                }
            } else {
                if ($this->verboseLogging) {
                    $this->logger->debug("Kein Service für Typ '{$typeName}' registriert, suche nach alternativen Auflösungsstrategien");
                }
            }
        }

        // Standardwert verwenden, falls verfügbar
        if ($param->isDefaultValueAvailable()) {
            if ($this->verboseLogging) {
                $this->logger->debug("Parameter '{$paramName}' für '{$className}' verwendet Standardwert");
            }
            return $param->getDefaultValue();
        }

        // Wenn Parameter optional ist, null zurückgeben
        if ($param->allowsNull()) {
            if ($this->verboseLogging) {
                $this->logger->debug("Parameter '{$paramName}' für '{$className}' ist optional, verwende null");
            }
            return null;
        }

        // Keine Auflösungsstrategie gefunden
        $error = "Konnte Parameter '{$paramName}' für Klasse '{$className}' nicht auflösen";
        $this->logger->error($error, [
            'parameter' => $paramName,
            'class' => $className,
            'type' => $paramType instanceof ReflectionNamedType ? $paramType->getName() : 'unknown'
        ]);

        throw new RuntimeException($error);
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
     * Holt einen Service aus dem Container
     *
     * @param string $id Service-ID
     * @return object Die Service-Instanz
     * @throws InvalidArgumentException Wenn der Service nicht gefunden wurde
     */
    public function get(string $id): object
    {
        try {
            // Prüfen ob Interface auf Implementierung gemappt ist
            if (isset($this->bindings[$id])) {
                $originalId = $id;
                $id = $this->bindings[$id];

                if ($this->verboseLogging) {
                    $this->logger->debug("Interface '{$originalId}' auf Implementierung '{$id}' gemappt");
                }
            }

            // Prüfen ob Service bereits existiert
            if (isset($this->services[$id])) {
                $service = $this->services[$id];

                // Falls es eine Factory ist, ausführen und Ergebnis cachen
                if ($service instanceof Closure) {
                    if ($this->verboseLogging) {
                        $this->logger->debug("Service '{$id}' wird aus Factory erstellt");
                    }

                    $service = $service();
                    $this->services[$id] = $service;

                    if ($this->verboseLogging) {
                        $this->logger->debug("Service '{$id}' aus Factory erstellt und gecached");
                    }
                }

                return $service;
            }

            // Versuchen, den Service automatisch zu erstellen
            if (class_exists($id)) {
                if ($this->verboseLogging) {
                    $this->logger->debug("Service '{$id}' nicht registriert, versuche Auto-Wiring");
                }

                $instance = $this->createInstance($id);
                $this->services[$id] = $instance;

                if ($this->verboseLogging) {
                    $this->logger->debug("Service '{$id}' automatisch erstellt und registriert");
                }

                return $instance;
            }

            $error = "Service nicht gefunden: $id";
            $this->logger->error($error, ['requested_service' => $id]);
            throw new InvalidArgumentException($error);
        } catch (Throwable $e) {
            if (!($e instanceof InvalidArgumentException)) {
                $this->logger->error("Fehler beim Abrufen des Service '{$id}': " . $e->getMessage(), [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }

            throw $e;
        }
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
        $className = $reflection->getName();

        if ($this->verboseLogging) {
            $this->logger->debug("Führe Property Injection für '{$className}' durch");
        }

        foreach ($reflection->getProperties() as $property) {
            // Nach Inject-Attribut suchen
            $attributes = $property->getAttributes(Inject::class);
            if (empty($attributes)) {
                continue;
            }

            $propertyName = $property->getName();

            try {
                /** @var Inject $inject */
                $inject = $attributes[0]->newInstance();
                $serviceId = $inject->serviceId;

                // Falls keine Service-ID angegeben, Typ verwenden
                if ($serviceId === null) {
                    $type = $property->getType();
                    if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                        if ($this->verboseLogging) {
                            $this->logger->debug("Property '{$propertyName}' in '{$className}' hat keinen gültigen Typ für Injection");
                        }
                        continue;
                    }
                    $serviceId = $type->getName();
                }

                if ($this->verboseLogging) {
                    $this->logger->debug("Injiziere Service '{$serviceId}' in Property '{$propertyName}' von '{$className}'");
                }

                // Eigenschaft zugänglich machen und Wert setzen
                $property->setAccessible(true);
                $property->setValue($instance, $this->get($serviceId));

                if ($this->verboseLogging) {
                    $this->logger->debug("Property Injection für '{$propertyName}' in '{$className}' erfolgreich");
                }
            } catch (Throwable $e) {
                $this->logger->error("Fehler bei Property Injection für '{$propertyName}' in '{$className}': " . $e->getMessage());
                // Fehler nicht nach oben propagieren, da Property Injection optional ist
            }
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
        $className = $reflection->getName();
        $observableProperties = [];

        // Observable Properties sammeln
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Observable::class);
            if (empty($attributes)) {
                continue;
            }

            $propertyName = $property->getName();

            try {
                /** @var Observable $observable */
                $observable = $attributes[0]->newInstance();
                $observableProperties[$propertyName] = $observable->callback;

                if ($this->verboseLogging) {
                    $callbackInfo = $observable->callback ? " mit Callback '{$observable->callback}'" : " ohne spezifischen Callback";
                    $this->logger->debug("Observable Property '{$propertyName}' in '{$className}' gefunden{$callbackInfo}");
                }
            } catch (Throwable $e) {
                $this->logger->error("Fehler beim Einrichten von Observable Property '{$propertyName}' in '{$className}': " . $e->getMessage());
            }
        }

        // Wenn keine Observable Properties gefunden wurden, nichts zu tun
        if (empty($observableProperties)) {
            return;
        }

        if ($this->verboseLogging) {
            $this->logger->debug("Richte Property Hooks für " . count($observableProperties) . " Properties in '{$className}' ein");
        }

        // Proxy-Handler erstellen und in WeakMap speichern
        $propertyValues = [];
        $proxy = new class($instance, $observableProperties, $propertyValues, $this->logger, $this->verboseLogging) {
            private object $instance;
            private array $observableProperties;
            private array $propertyValues;
            private LoggerInterface $logger;
            private bool $verboseLogging;

            public function __construct(object $instance, array $observableProperties, array &$propertyValues, LoggerInterface $logger, bool $verboseLogging)
            {
                $this->instance = $instance;
                $this->observableProperties = $observableProperties;
                $this->propertyValues = &$propertyValues;
                $this->logger = $logger;
                $this->verboseLogging = $verboseLogging;

                // Initialwerte erfassen
                $reflection = new ReflectionClass($instance);
                foreach (array_keys($observableProperties) as $propName) {
                    try {
                        $property = $reflection->getProperty($propName);
                        $property->setAccessible(true);
                        $this->propertyValues[$propName] = $property->getValue($instance);

                        if ($this->verboseLogging) {
                            $this->logger->debug("Initialwert für Observable Property '{$propName}' in '" . $reflection->getName() . "' erfasst");
                        }
                    } catch (Throwable $e) {
                        $this->logger->error("Fehler beim Erfassen des Initialwerts für Property '{$propName}': " . $e->getMessage());
                    }
                }
            }

            public function __set(string $name, mixed $value): void
            {
                $reflection = new ReflectionClass($this->instance);
                $className = $reflection->getName();

                // Nur Observable Properties überwachen
                if (!isset($this->observableProperties[$name])) {
                    try {
                        if ($reflection->hasProperty($name)) {
                            $property = $reflection->getProperty($name);
                            $property->setAccessible(true);
                            $property->setValue($this->instance, $value);

                            if ($this->verboseLogging) {
                                $this->logger->debug("Nicht-Observable Property '{$name}' in '{$className}' gesetzt");
                            }
                        }
                    } catch (Throwable $e) {
                        $this->logger->error("Fehler beim Setzen der Property '{$name}' in '{$className}': " . $e->getMessage());
                    }
                    return;
                }

                $oldValue = $this->propertyValues[$name] ?? null;

                // Wenn Wert gleich bleibt, nichts tun
                if ($oldValue === $value) {
                    if ($this->verboseLogging) {
                        $this->logger->debug("Wert für Observable Property '{$name}' in '{$className}' bleibt unverändert");
                    }
                    return;
                }

                try {
                    // Wert aktualisieren
                    $property = $reflection->getProperty($name);
                    $property->setAccessible(true);
                    $property->setValue($this->instance, $value);
                    $this->propertyValues[$name] = $value;

                    if ($this->verboseLogging) {
                        $this->logger->debug("Wert für Observable Property '{$name}' in '{$className}' geändert");
                    }

                    // Callback aufrufen, falls vorhanden
                    $callback = $this->observableProperties[$name];
                    if ($callback !== null && method_exists($this->instance, $callback)) {
                        if ($this->verboseLogging) {
                            $this->logger->debug("Rufe Property-Callback '{$callback}' für '{$name}' in '{$className}' auf");
                        }

                        $this->instance->{$callback}($oldValue, $value);
                    }

                    // Global PropertyHookAware-Interface aufrufen
                    if ($this->instance instanceof PropertyHookAware) {
                        if ($this->verboseLogging) {
                            $this->logger->debug("Rufe onPropertyChanged für '{$name}' in '{$className}' auf (PropertyHookAware)");
                        }

                        $this->instance->onPropertyChanged($name, $oldValue, $value);
                    }
                } catch (Throwable $e) {
                    $this->logger->error("Fehler beim Ändern der Observable Property '{$name}' in '{$className}': " . $e->getMessage(), [
                        'property' => $name,
                        'old_value' => $oldValue,
                        'new_value' => $value,
                        'exception' => get_class($e)
                    ]);
                }
            }

            public function __get(string $name): mixed
            {
                try {
                    $reflection = new ReflectionClass($this->instance);
                    if ($reflection->hasProperty($name)) {
                        $property = $reflection->getProperty($name);
                        $property->setAccessible(true);
                        return $property->getValue($this->instance);
                    }
                } catch (Throwable $e) {
                    $this->logger->error("Fehler beim Lesen der Property '{$name}': " . $e->getMessage());
                }

                return null;
            }
        };

        $this->propertyProxies[$instance] = $proxy;

        if ($this->verboseLogging) {
            $this->logger->debug("Property Hooks für '{$className}' erfolgreich eingerichtet");
        }
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

        if ($this->verboseLogging) {
            $this->logger->debug("Interface '{$interface}' an Implementierung '{$implementation}' gebunden");
        }

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

        if ($this->verboseLogging) {
            $paramKeys = implode(', ', array_keys($parameters));
            $this->logger->debug("Parameter für Service '{$id}' gesetzt: {$paramKeys}");
        }

        return $this;
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
        $className = get_class($instance);

        try {
            // Prüfen, ob für diese Instanz ein Proxy existiert
            if (!isset($this->propertyProxies[$instance])) {
                if ($this->verboseLogging) {
                    $this->logger->debug("Kein Proxy für '{$className}' vorhanden, direktes Property-Update");
                }

                // Keine Observable Properties für diese Instanz
                $reflection = new ReflectionClass($instance);
                if ($reflection->hasProperty($property)) {
                    $prop = $reflection->getProperty($property);
                    $prop->setAccessible(true);
                    $oldValue = $prop->getValue($instance);

                    if ($oldValue !== $value) {
                        $prop->setValue($instance, $value);

                        if ($this->verboseLogging) {
                            $this->logger->debug("Property '{$property}' in '{$className}' direkt aktualisiert");
                        }

                        return true;
                    }

                    if ($this->verboseLogging) {
                        $this->logger->debug("Property '{$property}' in '{$className}' bleibt unverändert");
                    }
                } else {
                    if ($this->verboseLogging) {
                        $this->logger->debug("Property '{$property}' existiert nicht in '{$className}'");
                    }
                }

                return false;
            }

            if ($this->verboseLogging) {
                $this->logger->debug("Aktualisiere Observable Property '{$property}' in '{$className}'");
            }

            // Proxy verwenden, um den Wert zu setzen und Hooks auszulösen
            $proxy = $this->propertyProxies[$instance];
            $oldValue = $proxy->{$property};

            if ($oldValue !== $value) {
                $proxy->{$property} = $value;

                if ($this->verboseLogging) {
                    $this->logger->debug("Observable Property '{$property}' in '{$className}' aktualisiert und Hooks ausgelöst");
                }

                return true;
            }

            if ($this->verboseLogging) {
                $this->logger->debug("Observable Property '{$property}' in '{$className}' bleibt unverändert");
            }

            return false;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Aktualisieren von Property '{$property}' in '{$className}': " . $e->getMessage());
            return false;
        }
    }
}