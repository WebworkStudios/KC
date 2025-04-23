<?php

declare(strict_types=1);

namespace Src\Core\Container;

use ReflectionClass;
use ReflectionNamedType;
use Src\Core\Container\ContainerInterface;
use Src\Core\Container\NotFoundExceptionInterface;
use Src\Core\Container\ContainerExceptionInterface;

/**
 * Container für Dependency Injection
 *
 * Ein einfacher und performanter DI-Container, der PSR-11 implementiert
 */
class Container implements ContainerInterface
{
    /**
     * Gespeicherte Instanzen (Singletons)
     */
    private array $instances = [];

    /**
     * Gespeicherte Bindungen
     */
    private array $bindings = [];

    /**
     * Cache für Reflection-Klassen
     */
    private array $reflectionCache = [];

    /**
     * Bindet eine Implementierung an eine Schnittstelle
     *
     * @param string $abstract Abstrakte Klasse oder Interface
     * @param \Closure|string|null $concrete Konkrete Implementierung
     */
    public function bind(string $abstract, \Closure|string|null $concrete = null): void
    {
        // Wenn keine konkrete Implementierung angegeben wurde, die abstrakte Klasse selbst verwenden
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Registriert eine Klasse als Singleton
     *
     * @param string $abstract Abstrakte Klasse oder Interface
     * @param mixed $concrete Konkrete Implementierung
     */
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        // Wenn null übergeben wurde, den abstrakten Typ als konkrete Implementierung verwenden
        if ($concrete === null) {
            $concrete = $abstract;
        }

        // Wenn bereits ein instanziiertes Objekt übergeben wurde, dieses direkt als Instanz registrieren
        if (is_object($concrete) && !($concrete instanceof \Closure)) {
            $this->instances[$abstract] = $concrete;
            return;
        }

        // Als Singleton markieren, indem wir eine Closure mit der konkreten Implementierung speichern
        $this->bindings[$abstract] = function () use ($concrete) {
            if ($concrete instanceof \Closure) {
                return $concrete($this);
            }

            return $this->build($concrete);
        };

        // Als Singleton markieren
        $this->instances[$abstract] = null;
    }

    /**
     * Baut eine Klasse mit ihren Abhängigkeiten auf
     *
     * @param string $concrete Vollständiger Klassenname
     * @param array $parameters Zusätzliche Parameter für den Konstruktor
     * @return object Instanziierte Klasse
     * @throws ContainerException
     */
    private function build(string $concrete, array $parameters = []): object
    {
        // Reflection-Informationen abrufen (mit Cache)
        if (!isset($this->reflectionCache[$concrete])) {
            $this->reflectionCache[$concrete] = new ReflectionClass($concrete);
        }
        $reflector = $this->reflectionCache[$concrete];

        // Prüfen, ob die Klasse instanziierbar ist
        if (!$reflector->isInstantiable()) {
            throw new ContainerException(
                "Klasse {$concrete} ist nicht instanziierbar. Stellen Sie sicher, dass es sich nicht um ein Interface oder eine abstrakte Klasse handelt."
            );
        }

        // Konstruktor abrufen
        $constructor = $reflector->getConstructor();

        // Wenn es keinen Konstruktor gibt oder keine Parameter benötigt werden, einfach instanziieren
        if ($constructor === null || empty($constructor->getParameters())) {
            return new $concrete;
        }

        // Parameter für den Konstruktor sammeln
        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            // Wenn der Parameter in den übergebenen Parametern enthalten ist, diesen verwenden
            $paramName = $parameter->getName();
            if (array_key_exists($paramName, $parameters)) {
                $dependencies[] = $parameters[$paramName];
                continue;
            }

            // Wenn der Parameter einen Typ hat, diesen auflösen
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                try {
                    $dependencies[] = $this->get($type->getName());
                    continue;
                } catch (ContainerException $e) {
                    // Weitermachen und andere Auflösungsmethoden versuchen
                }
            }

            // Wenn der Parameter optional ist, den Standardwert verwenden
            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }

            // Wenn wir hier ankommen, kann der Parameter nicht aufgelöst werden
            throw new ContainerException(
                "Parameter {$paramName} für Klasse {$concrete} kann nicht aufgelöst werden. " .
                "Binden Sie den Typ oder übergeben Sie einen Wert manuell."
            );
        }

        // Klasse mit den aufgelösten Abhängigkeiten instanziieren
        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Erstellt oder gibt eine Instanz einer Klasse zurück
     *
     * @param string $abstract Klasse, die erstellt werden soll
     * @param array $parameters Zusätzliche Parameter für den Konstruktor
     * @return mixed Instanz der Klasse
     * @throws ContainerException
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        // PSR-11 get() Methode mit Parametern aufrufen
        return $this->get($abstract, $parameters);
    }

    /**
     * Prüft, ob eine Bindung existiert (PSR-11)
     *
     * @param string $id Identifier der Klasse/des Services
     * @return bool True wenn verfügbar, sonst false
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    /**
     * Gibt eine Instanz aus dem Container zurück (PSR-11)
     *
     * @param string $id Identifier der Klasse/des Services
     * @param array $parameters Zusätzliche Parameter für den Konstruktor (nicht in PSR-11)
     * @return mixed Instanz der Klasse
     * @throws ContainerException|NotFoundException
     */
    public function get(string $id, array $parameters = []): mixed
    {
        // PSR-11: NotFoundExceptionInterface werfen, wenn der Eintrag nicht existiert
        if (!$this->has($id)) {
            throw new NotFoundException("Klasse oder Binding {$id} wurde nicht gefunden.");
        }

        // Wenn es bereits eine Instanz gibt, diese zurückgeben
        if (isset($this->instances[$id]) && $this->instances[$id] !== null) {
            return $this->instances[$id];
        }

        // Konkrete Implementierung ermitteln
        $concrete = $this->bindings[$id] ?? $id;

        try {
            // Instanz erstellen
            $instance = $concrete instanceof \Closure
                ? $concrete($this, $parameters)
                : $this->build($concrete, $parameters);

            // Bei Singletons die Instanz speichern
            if (array_key_exists($id, $this->instances)) {
                $this->instances[$id] = $instance;
            }

            return $instance;
        } catch (ContainerException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ContainerException(
                "Fehler beim Erstellen von {$id}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }
}