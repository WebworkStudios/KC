<?php


namespace Src\Database;

use Src\Database\Cache\ArrayCache;
use Src\Database\Cache\CacheInterface;
use Src\Database\Cache\NullCache;
use Src\Database\Enums\ConnectionMode;
use Src\Log\LoggerInterface;
use Src\Log\NullLogger;

/**
 * Factory für Datenbankkomponenten
 *
 * Vereinfacht die Erstellung und Konfiguration von Datenbankverbindungen und QueryBuildern
 */
class DatabaseFactory
{
    /** @var ConnectionManager|null Singleton-Instanz des ConnectionManagers */
    private static ?ConnectionManager $connectionManager = null;

    /** @var array<string, CacheInterface> Registrierte Cache-Provider */
    private static array $cacheProviders = [];

    /**
     * Erstellt einen ConnectionManager oder gibt die bestehende Instanz zurück
     *
     * @param LoggerInterface|null $logger Optional: Logger für Datenbankoperationen
     * @return ConnectionManager ConnectionManager-Instanz
     */
    public static function getConnectionManager(?LoggerInterface $logger = null): ConnectionManager
    {
        if (self::$connectionManager === null) {
            self::$connectionManager = new ConnectionManager($logger ?? new NullLogger());
        }

        return self::$connectionManager;
    }

    /**
     * Konfiguriert und registriert eine neue Datenbankverbindung
     *
     * @param string $name Name der Verbindung (z.B. 'forum', 'pages', 'game')
     * @param string $database Datenbankname
     * @param array<array{name: string, host: string, username: string, password: string, port?: int, type?: string}> $servers Server-Konfigurationen
     * @param LoadBalancingStrategy $loadBalancingStrategy Strategie für Loadbalancing
     * @param ConnectionMode $defaultMode Standardmodus für Verbindungen
     * @param LoggerInterface|null $logger Optional: Logger für Datenbankoperationen
     * @return ConnectionManager ConnectionManager-Instanz
     */
    public static function configureConnection(
        string                $name,
        string                $database,
        array                 $servers,
        LoadBalancingStrategy $loadBalancingStrategy = LoadBalancingStrategy::ROUND_ROBIN,
        ConnectionMode        $defaultMode = ConnectionMode::READ,
        ?LoggerInterface      $logger = null
    ): ConnectionManager
    {
        $connectionManager = self::getConnectionManager($logger);

        // ConnectionConfig erstellen
        $config = new ConnectionConfig(
            database: $database,
            loadBalancingStrategy: $loadBalancingStrategy,
            defaultMode: $defaultMode
        );

        // Server hinzufügen
        foreach ($servers as $serverConfig) {
            $server = new Server(
                name: $serverConfig['name'],
                host: $serverConfig['host'],
                username: $serverConfig['username'],
                password: $serverConfig['password'],
                port: $serverConfig['port'] ?? 3306
            );

            // Servertyp bestimmen (read, write oder beides)
            $type = $serverConfig['type'] ?? 'both';
            $isWriteServer = in_array($type, ['write', 'both', 'primary']);
            $isReadServer = in_array($type, ['read', 'both', 'primary']);

            $config->addServer($server, $isWriteServer, $isReadServer);
        }

        // Verbindung registrieren
        $connectionManager->registerConnection($name, $config);

        return $connectionManager;
    }

    /**
     * Erstellt einen neuen QueryBuilder für eine bestimmte Verbindung
     *
     * @param string $connectionName Name der Verbindung
     * @param string|null $table Optionaler Tabellenname für die Abfrage
     * @param LoggerInterface|null $logger Optional: Logger für Datenbankoperationen
     * @param CacheInterface|string|null $cache Optional: Cache-Provider oder Name eines registrierten Providers
     * @return QueryBuilder QueryBuilder-Instanz
     */
    public static function createQueryBuilder(
        string                     $connectionName,
        ?string                    $table = null,
        ?LoggerInterface           $logger = null,
        CacheInterface|string|null $cache = null
    ): QueryBuilder
    {
        $connectionManager = self::getConnectionManager($logger);
        $logger = $logger ?? new NullLogger();

        // Cache-Provider bestimmen
        $cacheProvider = null;

        if ($cache instanceof CacheInterface) {
            $cacheProvider = $cache;
        } elseif (is_string($cache) && isset(self::$cacheProviders[$cache])) {
            $cacheProvider = self::$cacheProviders[$cache];
        } else {
            $cacheProvider = new NullCache();
        }

        // QueryBuilder erstellen
        $queryBuilder = new QueryBuilder(
            connectionManager: $connectionManager,
            connectionName: $connectionName,
            logger: $logger,
            cache: $cacheProvider
        );

        // Tabelle setzen, falls angegeben
        if ($table !== null) {
            $queryBuilder->table($table);
        }

        return $queryBuilder;
    }

    /**
     * Registriert einen Cache-Provider
     *
     * @param string $name Name des Providers
     * @param CacheInterface $cache Cache-Provider
     * @return void
     */
    public static function registerCacheProvider(string $name, CacheInterface $cache): void
    {
        self::$cacheProviders[$name] = $cache;
    }

    /**
     * Erstellt eine In-Memory-Cache-Instanz und registriert sie
     *
     * @param string|null $name Optional: Name für die Registrierung
     * @return CacheInterface Cache-Instanz
     */
    public static function createArrayCache(?string $name = null): CacheInterface
    {
        $cache = new ArrayCache();

        if ($name !== null) {
            self::registerCacheProvider($name, $cache);
        }

        return $cache;
    }

    /**
     * Schließt alle Datenbankverbindungen
     *
     * @return void
     */
    public static function closeConnections(): void
    {
        if (self::$connectionManager !== null) {
            self::$connectionManager->closeAll();
        }
    }
}