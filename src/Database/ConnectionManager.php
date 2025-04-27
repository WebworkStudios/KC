<?php


namespace Src\Database;

use PDO;
use PDOException;
use Src\Database\Enums\ConnectionMode;
use Src\Database\Enums\ConnectionStatus;
use Src\Database\Exceptions\ConnectionException;
use Src\Log\LoggerInterface;

/**
 * Verwaltet mehrere Datenbankverbindungen mit Loadbalancing-Unterstützung
 */
class ConnectionManager
{
    /** @var array<string, ConnectionConfig> Konfigurationen für verschiedene Verbindungen */
    private array $configs = [];

    /** @var array<string, array<string, PDO>> Aktive Verbindungen [connectionName => [serverName => PDO]] */
    private array $connections = [];

    /** @var array<string, int> Zähler für Round-Robin Loadbalancing */
    private array $loadBalancerCounters = [];

    /** @var LoggerInterface Logger für Datenbankoperationen */
    private readonly LoggerInterface $logger;

    /**
     * Erstellt einen neuen Connection Manager
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Registriert eine neue Verbindungskonfiguration
     *
     * @param string $name Name der Verbindung (z.B. 'forum', 'pages', 'game')
     * @param ConnectionConfig $config Verbindungskonfiguration
     * @return self
     */
    public function registerConnection(string $name, ConnectionConfig $config): self
    {
        $this->configs[$name] = $config;
        $this->loadBalancerCounters[$name] = 0;
        $this->logger->info("Datenbankverbindung registriert", ['connection' => $name]);
        return $this;
    }

    /**
     * Prüft, ob eine Verbindungskonfiguration existiert
     *
     * @param string $name Name der Verbindung
     * @return bool
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->configs[$name]);
    }

    /**
     * Holt eine Verbindung mit Lazy-Loading
     *
     * @param string $name Name der Verbindung
     * @param bool $forceWrite True, um eine Schreibverbindung zu erzwingen
     * @return PDO PDO-Verbindung
     * @throws ConnectionException Wenn die Verbindung nicht hergestellt werden kann
     */
    public function getConnection(string $name, bool $forceWrite = false): PDO
    {
        if (!isset($this->configs[$name])) {
            $this->logger->error("Verbindung nicht gefunden", ['connection' => $name]);
            throw new ConnectionException("Datenbankverbindung '$name' nicht gefunden");
        }

        $config = $this->configs[$name];
        $mode = $forceWrite ? ConnectionMode::WRITE : $config->getDefaultMode();

        // Server basierend auf Modus und Loadbalancing-Strategie auswählen
        $selectedServer = $this->selectServer($name, $mode);
        $serverKey = $selectedServer->getName();

        // Prüfen, ob bereits eine Verbindung existiert
        if (isset($this->connections[$name][$serverKey])) {
            $connection = $this->connections[$name][$serverKey];

            // Prüfen, ob die Verbindung noch aktiv ist
            try {
                $connection->query('SELECT 1');
                return $connection;
            } catch (PDOException $e) {
                $this->logger->warning("Verbindung verloren, erstelle neue", [
                    'connection' => $name,
                    'server' => $serverKey
                ]);
                unset($this->connections[$name][$serverKey]);
            }
        }

        return $this->createConnection($name, $selectedServer);
    }

    /**
     * Wählt einen Server basierend auf Modus und Loadbalancing-Strategie aus
     *
     * @param string $name Name der Verbindung
     * @param ConnectionMode $mode Verbindungsmodus (READ/WRITE)
     * @return Server Ausgewählter Server
     * @throws ConnectionException Wenn kein passender Server gefunden wurde
     */
    private function selectServer(string $name, ConnectionMode $mode): Server
    {
        $config = $this->configs[$name];
        $servers = $mode === ConnectionMode::WRITE ? $config->getWriteServers() : $config->getReadServers();

        if (empty($servers)) {
            $this->logger->error("Keine Server verfügbar", [
                'connection' => $name,
                'mode' => $mode->name
            ]);
            throw new ConnectionException("Keine Server für Verbindung '$name' im Modus $mode->name verfügbar");
        }

        // Loadbalancing-Strategie anwenden
        switch ($config->getLoadBalancingStrategy()) {
            case LoadBalancingStrategy::RANDOM:
                return $servers[array_rand($servers)];

            case LoadBalancingStrategy::ROUND_ROBIN:
                // Zähler erhöhen und modulo Anzahl der Server
                $counter = &$this->loadBalancerCounters[$name];
                $counter = ($counter + 1) % count($servers);
                return $servers[$counter];

            case LoadBalancingStrategy::LEAST_CONNECTIONS:
                // TODO: Implementierung für least_connections
                return $servers[0];

            default:
                // Bei unbekannter Strategie den ersten Server verwenden
                return $servers[0];
        }
    }

    /**
     * Erstellt eine neue PDO-Verbindung zu einem Server
     *
     * @param string $name Name der Verbindung
     * @param Server $server Server-Konfiguration
     * @return PDO PDO-Verbindung
     * @throws ConnectionException Bei Verbindungsfehlern
     */
    private function createConnection(string $name, Server $server): PDO
    {
        $config = $this->configs[$name];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $server->getHost(),
            $server->getPort(),
            $config->getDatabase()
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'
        ];

        try {
            $this->logger->debug("Verbindungsaufbau", [
                'connection' => $name,
                'server' => $server->getName(),
                'host' => $server->getHost()
            ]);

            $pdo = new PDO(
                $dsn,
                $server->getUsername(),
                $server->getPassword(),
                $options
            );

            // Verbindung speichern
            if (!isset($this->connections[$name])) {
                $this->connections[$name] = [];
            }
            $this->connections[$name][$server->getName()] = $pdo;

            return $pdo;
        } catch (PDOException $e) {
            $this->logger->error("Verbindungsfehler", [
                'connection' => $name,
                'server' => $server->getName(),
                'message' => $e->getMessage()
            ]);

            throw new ConnectionException(
                "Fehler beim Verbinden zu Datenbank '{$name}' auf Server '{$server->getName()}': {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Schließt alle aktiven Verbindungen
     *
     * @return void
     */
    public function closeAll(): void
    {
        foreach ($this->connections as $connectionName => $servers) {
            foreach ($servers as $serverName => $pdo) {
                $this->logger->debug("Verbindung geschlossen", [
                    'connection' => $connectionName,
                    'server' => $serverName
                ]);

                // PDO-Verbindung auf null setzen, um sie zu schließen
                $this->connections[$connectionName][$serverName] = null;
            }
        }

        $this->connections = [];
        $this->logger->info("Alle Datenbankverbindungen geschlossen");
    }

    /**
     * Schließt alle Verbindungen für eine bestimmte Konfiguration
     *
     * @param string $name Name der Verbindung
     * @return void
     */
    public function closeConnection(string $name): void
    {
        if (isset($this->connections[$name])) {
            foreach ($this->connections[$name] as $serverName => $pdo) {
                $this->logger->debug("Verbindung geschlossen", [
                    'connection' => $name,
                    'server' => $serverName
                ]);

                // PDO-Verbindung auf null setzen, um sie zu schließen
                $this->connections[$name][$serverName] = null;
            }

            unset($this->connections[$name]);
            $this->logger->info("Alle Verbindungen für '{$name}' geschlossen");
        }
    }

    /**
     * Gibt einen Status-Report für alle Verbindungen zurück
     *
     * @return array<string, array<string, string>> Status-Report
     */
    public function getConnectionStatus(): array
    {
        $status = [];

        foreach ($this->configs as $name => $config) {
            $status[$name] = [];

            // Status für jeden konfigurierten Server
            foreach ($config->getAllServers() as $server) {
                $serverName = $server->getName();

                if (isset($this->connections[$name][$serverName])) {
                    try {
                        // Verbindung testen
                        $this->connections[$name][$serverName]->query('SELECT 1');
                        $status[$name][$serverName] = ConnectionStatus::CONNECTED->value;
                    } catch (PDOException $e) {
                        $status[$name][$serverName] = ConnectionStatus::ERROR->value;
                    }
                } else {
                    $status[$name][$serverName] = ConnectionStatus::DISCONNECTED->value;
                }
            }
        }

        return $status;
    }
}