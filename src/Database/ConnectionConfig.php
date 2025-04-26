<?php

namespace Src\Database;

use Src\Database\Enums\ConnectionMode;

/**
 * Konfiguration für eine Datenbankverbindung mit mehreren Servern und Loadbalancing
 */
class ConnectionConfig
{
    /** @var array<string, Server> Alle Server nach Namen */
    private array $servers = [];

    /** @var array<Server> Schreib-Server */
    private array $writeServers = [];

    /** @var array<Server> Lese-Server */
    private array $readServers = [];

    /**
     * Erstellt eine neue Verbindungskonfiguration
     *
     * @param string $database Name der Datenbank
     * @param LoadBalancingStrategy $loadBalancingStrategy Strategie für Loadbalancing
     * @param ConnectionMode $defaultMode Standardmodus für Verbindungen
     */
    public function __construct(
        private readonly string $database,
        private readonly LoadBalancingStrategy $loadBalancingStrategy = LoadBalancingStrategy::ROUND_ROBIN,
        private readonly ConnectionMode $defaultMode = ConnectionMode::READ
    ) {
    }

    /**
     * Fügt einen Server zur Konfiguration hinzu
     *
     * @param Server $server Server-Konfiguration
     * @param bool $isWriteServer True, wenn Server für Schreiboperationen verwendet werden kann
     * @param bool $isReadServer True, wenn Server für Leseoperationen verwendet werden kann
     * @return self
     */
    public function addServer(Server $server, bool $isWriteServer = true, bool $isReadServer = true): self
    {
        $this->servers[$server->getName()] = $server;

        if ($isWriteServer) {
            $this->writeServers[] = $server;
        }

        if ($isReadServer) {
            $this->readServers[] = $server;
        }

        return $this;
    }

    /**
     * Fügt einen primären Server hinzu (für Schreib- und Leseoperationen)
     *
     * @param string $name Name des Servers
     * @param string $host Hostname
     * @param string $username Benutzername
     * @param string $password Passwort
     * @param int $port Port (Standard: 3306)
     * @return self
     */
    public function addPrimaryServer(
        string $name,
        string $host,
        string $username,
        string $password,
        int $port = 3306
    ): self {
        $server = new Server($name, $host, $username, $password, $port);
        return $this->addServer($server, true, true);
    }

    /**
     * Fügt einen Read-Only-Server hinzu (nur für Leseoperationen)
     *
     * @param string $name Name des Servers
     * @param string $host Hostname
     * @param string $username Benutzername
     * @param string $password Passwort
     * @param int $port Port (Standard: 3306)
     * @return self
     */
    public function addReadOnlyServer(
        string $name,
        string $host,
        string $username,
        string $password,
        int $port = 3306
    ): self {
        $server = new Server($name, $host, $username, $password, $port);
        return $this->addServer($server, false, true);
    }

    /**
     * Gibt den Namen der Datenbank zurück
     *
     * @return string
     */
    public function getDatabase(): string
    {
        return $this->database;
    }

    /**
     * Gibt die Loadbalancing-Strategie zurück
     *
     * @return LoadBalancingStrategy
     */
    public function getLoadBalancingStrategy(): LoadBalancingStrategy
    {
        return $this->loadBalancingStrategy;
    }

    /**
     * Gibt den Standardmodus für Verbindungen zurück
     *
     * @return ConnectionMode
     */
    public function getDefaultMode(): ConnectionMode
    {
        return $this->defaultMode;
    }

    /**
     * Gibt alle Server zurück
     *
     * @return array<Server>
     */
    public function getAllServers(): array
    {
        return array_values($this->servers);
    }

    /**
     * Gibt alle Schreib-Server zurück
     *
     * @return array<Server>
     */
    public function getWriteServers(): array
    {
        return $this->writeServers;
    }

    /**
     * Gibt alle Lese-Server zurück
     *
     * @return array<Server>
     */
    public function getReadServers(): array
    {
        return $this->readServers;
    }

    /**
     * Gibt einen Server anhand seines Namens zurück
     *
     * @param string $name Name des Servers
     * @return Server|null Server oder null, wenn nicht gefunden
     */
    public function getServerByName(string $name): ?Server
    {
        return $this->servers[$name] ?? null;
    }
}