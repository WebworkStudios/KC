<?php

namespace Src\Database;

use Closure;
use Exception;
use Throwable;

/**
 * Future-Klasse für asynchrone Datenbankabfragen
 *
 * @template T
 */
class Future
{
    /** @var Closure Closure, die die Datenbankabfrage ausführt */
    private Closure $executor;

    /** @var T|null Ergebnis der Abfrage oder null, wenn noch nicht ausgeführt */
    private mixed $result = null;

    /** @var bool Flag, ob die Abfrage bereits ausgeführt wurde */
    private bool $isExecuted = false;

    /** @var Exception|null Fehler, der bei der Ausführung aufgetreten ist */
    private ?Throwable $error = null;

    /** @var array<Closure> Callbacks, die nach erfolgreicher Ausführung aufgerufen werden */
    private array $successCallbacks = [];

    /** @var array<Closure> Callbacks, die nach fehlgeschlagener Ausführung aufgerufen werden */
    private array $failureCallbacks = [];

    /** @var array<Closure> Callbacks, die nach Ausführung aufgerufen werden (unabhängig vom Erfolg) */
    private array $finallyCallbacks = [];

    /**
     * Erstellt eine neue Future-Instanz
     *
     * @param QueryBuilder $query Abfrage, die ausgeführt werden soll
     */
    public function __construct(QueryBuilder $query)
    {
        // Ergebnis der get()-Methode als Executor-Closure speichern
        $this->executor = function() use ($query) {
            return $query->get();
        };
    }

    /**
     * Fügt einen Callback hinzu, der nach erfolgreicher Ausführung aufgerufen wird
     *
     * @param Closure(T): mixed $callback Callback-Funktion
     * @return self
     */
    public function then(Closure $callback): self
    {
        $this->successCallbacks[] = $callback;

        // Wenn bereits ausgeführt und erfolgreich, Callback direkt ausführen
        if ($this->isExecuted && $this->error === null) {
            $callback($this->result);
        }

        return $this;
    }

    /**
     * Fügt einen Callback hinzu, der nach fehlgeschlagener Ausführung aufgerufen wird
     *
     * @param Closure(Throwable): mixed $callback Callback-Funktion
     * @return self
     */
    public function catch(Closure $callback): self
    {
        $this->failureCallbacks[] = $callback;

        // Wenn bereits ausgeführt und fehlgeschlagen, Callback direkt ausführen
        if ($this->isExecuted && $this->error !== null) {
            $callback($this->error);
        }

        return $this;
    }

    /**
     * Fügt einen Callback hinzu, der nach Ausführung aufgerufen wird (unabhängig vom Erfolg)
     *
     * @param Closure(): mixed $callback Callback-Funktion
     * @return self
     */
    public function finally(Closure $callback): self
    {
        $this->finallyCallbacks[] = $callback;

        // Wenn bereits ausgeführt, Callback direkt ausführen
        if ($this->isExecuted) {
            $callback();
        }

        return $this;
    }

    /**
     * Führt die Abfrage aus, falls noch nicht geschehen
     *
     * @return T Ergebnis der Abfrage
     * @throws Throwable Bei Fehlern während der Ausführung
     */
    public function get(): mixed
    {
        // Wenn bereits ausgeführt, direkt das Ergebnis zurückgeben oder den Fehler werfen
        if ($this->isExecuted) {
            if ($this->error !== null) {
                throw $this->error;
            }

            return $this->result;
        }

        // Abfrage ausführen
        try {
            $this->result = ($this->executor)();

            // Erfolg-Callbacks aufrufen
            foreach ($this->successCallbacks as $callback) {
                $callback($this->result);
            }
        } catch (Throwable $e) {
            $this->error = $e;

            // Fehler-Callbacks aufrufen
            foreach ($this->failureCallbacks as $callback) {
                $callback($e);
            }

            // Wenn keine Fehler-Callbacks registriert wurden, Fehler werfen
            if (empty($this->failureCallbacks)) {
                throw $e;
            }
        } finally {
            $this->isExecuted = true;

            // Finally-Callbacks aufrufen
            foreach ($this->finallyCallbacks as $callback) {
                $callback();
            }
        }

        return $this->result;
    }

    /**
     * Prüft, ob die Abfrage bereits ausgeführt wurde
     *
     * @return bool True, wenn die Abfrage bereits ausgeführt wurde
     */
    public function isExecuted(): bool
    {
        return $this->isExecuted;
    }

    /**
     * Prüft, ob ein Fehler aufgetreten ist
     *
     * @return bool True, wenn ein Fehler aufgetreten ist
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Gibt den aufgetretenen Fehler zurück oder null, wenn kein Fehler aufgetreten ist
     *
     * @return Throwable|null Fehler oder null
     */
    public function getError(): ?Throwable
    {
        return $this->error;
    }

    /**
     * Erstellt eine neue Future-Instanz mit einem angepassten Executor
     *
     * @param Closure(): T $executor Executor-Funktion
     * @return self Neue Future-Instanz
     */
    public static function create(Closure $executor): self
    {
        $future = new self(new QueryBuilder(
        // Dummy QueryBuilder ohne echte Funktionalität, nur um den Konstruktor zu erfüllen
        // Der tatsächliche Code wird vom bereitgestellten Executor ausgeführt
            new ConnectionManager(new \Src\Log\NullLogger()),
            'dummy',
            new \Src\Log\NullLogger()
        ));

        // Executor überschreiben
        $future->executor = $executor;

        return $future;
    }

    /**
     * Wartet auf die Ausführung mehrerer Futures
     *
     * @param array<Future> $futures Array von Future-Instanzen
     * @return array Array mit den Ergebnissen der Futures
     * @throws Throwable Bei Fehlern während der Ausführung
     */
    public static function all(array $futures): array
    {
        $results = [];

        foreach ($futures as $key => $future) {
            $results[$key] = $future->get();
        }

        return $results;
    }

    /**
     * Führt die erste erfolgreiche Future aus mehreren aus
     *
     * @param array<Future> $futures Array von Future-Instanzen
     * @return mixed Ergebnis der ersten erfolgreichen Future
     * @throws Throwable Wenn alle Futures fehlschlagen
     */
    public static function any(array $futures): mixed
    {
        $lastError = null;

        foreach ($futures as $future) {
            try {
                return $future->get();
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        // Wenn alle Futures fehlschlagen, den letzten Fehler werfen
        if ($lastError !== null) {
            throw new Exception("Alle Futures sind fehlgeschlagen: " . $lastError->getMessage(), 0, $lastError);
        }

        // Sollte nie erreicht werden, da mindestens ein Future in $futures sein sollte
        throw new Exception("Keine Futures angegeben");
    }
}