<?php

namespace Src\Database;

use Redis;
use RuntimeException;
use Src\Database\Cache\RedisCache;
use Src\Database\Enums\ConnectionMode;
use Src\Log\LoggerInterface;
use Src\Log\NullLogger;
use Throwable;

/**
 * Erweiterung der DatabaseFactory für Redis-Cache-Integration
 *
 * Bietet statische Methoden für die einfache Erstellung und Konfiguration
 * von Redis-basierten Datenbankverbindungen mit Cache-Unterstützung
 */
class DatabaseFactoryRedisExtension
{
    /**
     * Erstellt einen QueryBuilder mit Redis-Cache
     *
     * @param string $connectionName Name der Datenbankverbindung
     * @param array $redisConfig Redis-Konfiguration
     * @param string|null $table Optionaler Tabellenname
     * @param LoggerInterface|null $logger Optional: Logger für Datenbankoperationen
     * @param string $cacheName Optional: Name für die Cache-Registrierung
     * @return QueryBuilder QueryBuilder-Instanz mit Redis-Cache
     */
    public static function createQueryBuilderWithRedisCache(
        string           $connectionName,
        array            $redisConfig,
        ?string          $table = null,
        ?LoggerInterface $logger = null,
        string           $cacheName = 'redis_cache'
    ): QueryBuilder
    {
        // Redis-Cache erstellen und registrieren
        $cache = self::createRedisCache($cacheName, $redisConfig, $logger);

        // QueryBuilder mit Cache erstellen
        return DatabaseFactory::createQueryBuilder(
            connectionName: $connectionName,
            table: $table,
            logger: $logger,
            cache: $cache
        );
    }

    /**
     * Erstellt und registriert einen Redis-Cache-Provider
     *
     * @param string $name Name für die Cache-Registrierung
     * @param array $config Redis-Konfiguration
     * @param LoggerInterface|null $logger Optional: Logger für Cache-Operationen
     * @return RedisCache Redis-Cache-Instanz
     * @throws RuntimeException Wenn die Redis-Erweiterung nicht verfügbar ist oder Verbindung fehlschlägt
     */
    public static function createRedisCache(
        string           $name,
        array            $config = [],
        ?LoggerInterface $logger = null
    ): RedisCache
    {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('Die Redis-Erweiterung ist nicht verfügbar');
        }

        $logger ??= new NullLogger();

        // Redis-Konfiguration aus Array extrahieren
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $timeout = $config['timeout'] ?? 0.0;
        $password = $config['password'] ?? null;
        $database = $config['database'] ?? 0;
        $prefix = $config['prefix'] ?? 'db_cache:';
        $persistent = $config['persistent'] ?? true;

        // Redis-Instanz erstellen
        $redis = new Redis();
        $cache = new RedisCache($redis, $prefix, $logger);

        // Verbindung herstellen
        try {
            if ($persistent) {
                $connected = $cache->pconnect($host, $port, $timeout, $password, $database);
            } else {
                $connected = $cache->connect($host, $port, $timeout, $password, $database);
            }

            if (!$connected) {
                throw new RuntimeException("Konnte keine Verbindung zum Redis-Server herstellen: $host:$port");
            }
        } catch (Throwable $e) {
            $logger->error("Redis-Verbindungsfehler: " . $e->getMessage(), [
                'host' => $host,
                'port' => $port,
                'exception' => get_class($e)
            ]);
            throw $e;
        }

        // Cache in Factory registrieren
        DatabaseFactory::registerCacheProvider($name, $cache);

        $logger->info("Redis-Cache-Provider registriert", [
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'persistent' => $persistent
        ]);

        return $cache;
    }

    /**
     * Konfiguriert eine Datenbankverbindung mit Redis-Cache
     *
     * @param string $name Name der Datenbankverbindung
     * @param string $database Datenbankname
     * @param array $servers Serverinformationen
     * @param array $redisConfig Redis-Konfiguration
     * @param LoggerInterface|null $logger Optional: Logger
     * @param LoadBalancingStrategy $loadBalancingStrategy Loadbalancing-Strategie
     * @param ConnectionMode $defaultMode Standardmodus für Verbindungen
     * @param string $cacheName Name für den Cache-Provider
     * @return ConnectionManager ConnectionManager-Instanz
     */
    public static function configureConnectionWithRedisCache(
        string                $name,
        string                $database,
        array                 $servers,
        array                 $redisConfig,
        ?LoggerInterface      $logger = null,
        LoadBalancingStrategy $loadBalancingStrategy = LoadBalancingStrategy::ROUND_ROBIN,
        ConnectionMode        $defaultMode = ConnectionMode::READ,
        string                $cacheName = 'redis_cache'
    ): ConnectionManager
    {
        // Datenbankverbindung konfigurieren
        $connectionManager = DatabaseFactory::configureConnection(
            name: $name,
            database: $database,
            servers: $servers,
            loadBalancingStrategy: $loadBalancingStrategy,
            defaultMode: $defaultMode,
            logger: $logger
        );

        // Redis-Cache erstellen und registrieren
        self::createRedisCache($cacheName, $redisConfig, $logger);

        return $connectionManager;
    }
}