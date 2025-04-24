<?php

declare(strict_types=1);

namespace App\Core\Database;

/**
 * Verwaltet Datenbankverbindungen
 */
class DatabaseManager
{
    /**
     * Aktive Verbindungen
     */
    private array $connections = [];

    /**
     * Konfigurationen für Verbindungen
     */
    private array $configs = [];

    /**
     * Standardverbindung
     */
    private string $defaultConnection = 'default';

    /**
     * QueryBuilderFactory-Instanz
     */
    private ?QueryBuilderFactory $factory = null;

    /**
     * Verbindungs-Timeout in Sekunden
     */
    private int $connectionTimeout = 300; // 5 Minuten

    /**
     * Zeitstempel der letzten Nutzung für Verbindungen
     */
    private array $connectionLastUsed = [];

    /**
     * Minimale Anzahl aktiver Verbindungen im Pool
     */
    private int $minConnections = 1;

    /**
     * Maximale Anzahl aktiver Verbindungen im Pool
     */
    private int $maxConnections = 10;


    /**
     * Konstruktor
     *
     * @param array $configs Konfigurationen für Verbindungen
     * @param string $defaultConnection Standardverbindung
     */
    public function __construct(array $configs = [], string $defaultConnection = 'default')
    {
        $this->configs = $configs;
        $this->defaultConnection = $defaultConnection;
    }

    /**
     * Setzt die Konfigurationen für Verbindungen
     *
     * @param array $configs Konfigurationen für Verbindungen
     * @return self
     */
    public function setConfigs(array $configs): self
    {
        $this->configs = $configs;

        return $this;
    }

    /**
     * Setzt die Standardverbindung
     *
     * @param string $defaultConnection Standardverbindung
     * @return self
     */
    public function setDefaultConnection(string $defaultConnection): self
    {
        $this->defaultConnection = $defaultConnection;

        return $this;
    }

    /**
     * Erstellt eine Unterabfrage
     *
     * @param callable $callback Die Callback-Funktion, die einen QueryBuilder erhält
     * @param string|null $alias Der Alias für die Unterabfrage
     * @param string|null $connection Die Verbindung oder null für die Standardverbindung
     * @return SubQueryBuilder
     */
    public function subQuery(callable $callback, ?string $alias = null, ?string $connection = null): SubQueryBuilder
    {
        return $this->getFactory()->subQuery($callback, $alias, $connection);
    }

    /**
     * Gibt die QueryBuilderFactory zurück oder erstellt sie
     *
     * @return QueryBuilderFactory
     */
    public function getFactory(): QueryBuilderFactory
    {
        if ($this->factory === null) {
            $this->factory = new QueryBuilderFactory($this);
        }

        return $this->factory;
    }

    /**
     * Führt eine Funktion in einer Transaktion aus
     *
     * @param callable(Connection): mixed $callback Die Callback-Funktion
     * @param string|null $connection Die Verbindung oder null für die Standardverbindung
     * @return mixed Das Ergebnis des Callbacks
     * @throws \Throwable wenn ein Fehler auftritt
     */
    public function transaction(callable $callback, ?string $connection = null): mixed
    {
        return $this->connection($connection)->transaction($callback);
    }


    /**
     * Gibt eine Verbindung zurück oder erstellt eine neue
     */
    public function connection(?string $name = null): Connection
    {
        $name = $name ?? $this->defaultConnection;

        // Verbindung erstellen, wenn nicht vorhanden
        if (!isset($this->connections[$name])) {
            if (!isset($this->configs[$name])) {
                throw new \Exception("Datenbankverbindung '{$name}' ist nicht konfiguriert.");
            }

            // Prüfen, ob maximale Verbindungsanzahl erreicht ist
            if (count($this->connections) >= $this->maxConnections) {
                // Versuchen, eine inaktive Verbindung zu schließen
                $this->pruneInactiveConnections();

                // Falls immer noch zu viele Verbindungen, Exception werfen
                if (count($this->connections) >= $this->maxConnections) {
                    throw new \Exception("Maximale Anzahl an Datenbankverbindungen erreicht.");
                }
            }

            $this->connections[$name] = new Connection($this->configs[$name]);
        }

        // Zeitstempel aktualisieren
        $this->connectionLastUsed[$name] = time();

        return $this->connections[$name];
    }

    /**
     * Schließt inaktive Verbindungen
     */
    private function cleanInactiveConnections(): void
    {
        $now = time();

        foreach ($this->connectionLastUsed as $name => $lastUsed) {
            if ($now - $lastUsed > $this->connectionTimeout) {
                // Verbindung schließen
                if (isset($this->connections[$name])) {
                    $this->connections[$name]->disconnect();
                    unset($this->connections[$name]);
                }

                // Aus den Zeitstempeln entfernen
                unset($this->connectionLastUsed[$name]);
            }
        }
    }

    /**
     * Schließt alle aktiven Verbindungen
     *
     * @return void
     */
    public function disconnect(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }

        $this->connections = [];
    }

    /**
     * Führt eine rohe SQL-Abfrage aus
     *
     * @param string $query Die SQL-Abfrage
     * @param array $bindings Die Parameter
     * @param string|null $connection Die Verbindung oder null für die Standardverbindung
     * @return array Die Ergebnisse
     */
    public function query(string $query, array $params = [], ?string $connection = null): array
    {
        return $this->connection($connection)?->select($query, $params) ?? [];
    }

    /**
     * Führt eine Einfügeoperation mit mehreren Datensätzen aus
     *
     * @param string $table Die Tabelle
     * @param array $data Die Daten
     * @param string|null $connection Die Verbindung oder null für die Standardverbindung
     * @return bool true bei Erfolg
     */
    public function insertBatch(string $table, array $data, ?string $connection = null): bool
    {
        return $this->table($table, $connection)->insertMany($data);
    }

    /**
     * Shortcut für connection()->table()
     *
     * @param string $table Tabellenname
     * @param string|null $connection Name der Verbindung oder null für die Standardverbindung
     * @return QueryBuilder
     * @throws \Exception wenn die Verbindung nicht konfiguriert ist
     */
    public function table(string $table, ?string $connection = null): QueryBuilder
    {
        return $this->connection($connection)->table($table);
    }

    /**
     * Entfernt inaktive Verbindungen, um Ressourcen freizugeben
     */
    private function pruneInactiveConnections(): void
    {
        $now = time();
        $totalClosed = 0;

        // Nach alten Verbindungen suchen
        foreach ($this->connectionLastUsed as $name => $lastUsed) {
            // Nur schließen, wenn mehr als minimale Anzahl aktiver Verbindungen
            if (count($this->connections) > $this->minConnections &&
                $now - $lastUsed > $this->connectionTimeout) {

                // Verbindung schließen
                if (isset($this->connections[$name])) {
                    $this->connections[$name]->disconnect();
                    unset($this->connections[$name]);
                    unset($this->connectionLastUsed[$name]);
                    $totalClosed++;
                }
            }
        }
    }
}