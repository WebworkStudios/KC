<?php

/**
 * Bootstrap für die Anwendung
 *
 * Initialisiert den Container, die Konfiguration und andere Kernkomponenten
 *
 * PHP Version 8.4
 */

declare(strict_types=1);

use Src\Config;
use Src\Container\Container;
use Src\Log\LoggerFactory;
use Src\Log\LoggerInterface;
use Src\Http\Middleware\LoggingMiddleware;
use Src\Database\DatabaseFactory;
use Src\Database\LoadBalancingStrategy;
use Src\Database\Enums\ConnectionMode;

/**
 * Container initialisieren und konfigurieren
 *
 * @param array $config Zusätzliche Konfiguration
 * @return Container Konfigurierter Container
 */
function bootstrapContainer(array $config = []): Container
{
    // Container erstellen
    $container = new Container();

    // Konfiguration registrieren
    $appConfig = new Config($config);
    $container->register(Config::class, $appConfig);

    // Logger einrichten
    $loggerFactory = new LoggerFactory(
        $appConfig->get('logging.dir', BASE_PATH . '/logs'),
        $appConfig->get('logging.level', 'debug')
    );

    $container->register(LoggerFactory::class, $loggerFactory);

    // Standard-Logger erstellen und registrieren
    $environment = $appConfig->get('app.environment', 'development');
    $logger = $loggerFactory->createDefaultLogger($environment, [
        'filename' => 'app.log',
        'level' => $appConfig->get('logging.level', 'debug'),
    ]);

    // Logger im Container registrieren
    $container->register(LoggerInterface::class, $logger);

    // Logger im Container selbst setzen
    $container->setLogger($logger);

    // Verbose Logging im Entwicklungsmodus aktivieren
    if ($environment === 'development') {
        $container->setVerboseLogging(true);
    }

    // LoggingMiddleware registrieren
    $container->register(LoggingMiddleware::class, new LoggingMiddleware($logger));

    // Datenbank initialisieren, falls konfiguriert
    initializeDatabase($container, $appConfig);

    return $container;
}

/**
 * Datenbank initialisieren, falls konfiguriert
 *
 * @param Container $container DI-Container
 * @param Config $config Konfiguration
 * @return void
 */
function initializeDatabase(Container $container, Config $config): void
{
    // Prüfen, ob Datenbank konfiguriert ist
    $dbConfig = $config->get('database');
    if (empty($dbConfig)) {
        return;
    }

    $logger = $container->get(LoggerInterface::class);

    try {
        // Standard-Datenbankverbindung konfigurieren
        $connectionName = $dbConfig['default_connection'] ?? 'main';
        $database = $dbConfig['connections'][$connectionName]['database'] ?? null;

        if (empty($database)) {
            $logger->warning('Keine Datenbank-Konfiguration gefunden');
            return;
        }

        // Server-Konfigurationen auslesen
        $servers = $dbConfig['connections'][$connectionName]['servers'] ?? [];
        if (empty($servers)) {
            // Falls keine expliziten Server konfiguriert sind, einen aus den Basiseinstellungen erstellen
            $servers = [[
                'name' => 'default',
                'host' => $dbConfig['connections'][$connectionName]['host'] ?? 'localhost',
                'port' => $dbConfig['connections'][$connectionName]['port'] ?? 3306,
                'username' => $dbConfig['connections'][$connectionName]['username'] ?? 'root',
                'password' => $dbConfig['connections'][$connectionName]['password'] ?? '',
                'type' => 'primary'
            ]];
        }

        // Loadbalancing-Strategie und Standardmodus bestimmen
        $loadBalancingStrategy = LoadBalancingStrategy::ROUND_ROBIN;
        if (isset($dbConfig['connections'][$connectionName]['load_balancing'])) {
            $loadBalancingStrategy = match($dbConfig['connections'][$connectionName]['load_balancing']) {
                'random' => LoadBalancingStrategy::RANDOM,
                'least_connections' => LoadBalancingStrategy::LEAST_CONNECTIONS,
                default => LoadBalancingStrategy::ROUND_ROBIN
            };
        }

        $defaultMode = ConnectionMode::READ;
        if (isset($dbConfig['connections'][$connectionName]['default_mode'])) {
            $defaultMode = $dbConfig['connections'][$connectionName]['default_mode'] === 'write'
                ? ConnectionMode::WRITE
                : ConnectionMode::READ;
        }

        // Datenbankverbindung konfigurieren
        $connectionManager = DatabaseFactory::configureConnection(
            name: $connectionName,
            database: $database,
            servers: $servers,
            loadBalancingStrategy: $loadBalancingStrategy,
            defaultMode: $defaultMode,
            logger: $logger
        );

        // ConnectionManager im Container registrieren
        $container->register('ConnectionManager', $connectionManager);

        $logger->info('Datenbank initialisiert', [
            'connection' => $connectionName,
            'database' => $database
        ]);

    } catch (Throwable $e) {
        $logger->error('Fehler bei Datenbank-Initialisierung: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
}

/**
 * Session-Bootstrap einbinden, falls vorhanden
 *
 * @param Container $container DI-Container
 * @param array $config Konfiguration
 * @return void
 */
function bootstrapSession(Container $container, array $config): void
{
    $sessionBootstrapFile = BASE_PATH . '/app/session-bootstrap.php';

    if (file_exists($sessionBootstrapFile)) {
        require_once $sessionBootstrapFile;

        if (function_exists('bootstrapSessions')) {
            bootstrapSessions($container, $config);
        }
    }
}

/**
 * Cache-Bootstrap einbinden, falls vorhanden
 *
 * @param Container $container DI-Container
 * @param array $config Konfiguration
 * @return void
 */
function initializeCache(Container $container, array $config): void
{
    $cacheBootstrapFile = BASE_PATH . '/app/cache-bootstrap.php';

    if (file_exists($cacheBootstrapFile)) {
        require_once $cacheBootstrapFile;

        if (function_exists('bootstrapCache')) {
            bootstrapCache($container, $config);
        }
    }
}

/**
 * Gibt eine Umgebungsvariable zurück oder den Standardwert, falls die Variable nicht existiert
 *
 * Helper-Funktion für Konfigurationsdateien
 *
 * @param string $key Name der Umgebungsvariable
 * @param mixed $default Standardwert
 * @return mixed Wert der Umgebungsvariable oder Standardwert
 */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null) {
        return $default;
    }

    // TRUE/FALSE-Werte umwandeln
    if (strtolower($value) === 'true') {
        return true;
    }

    if (strtolower($value) === 'false') {
        return false;
    }

    // NULL-Werte umwandeln
    if (strtolower($value) === 'null') {
        return null;
    }

    return $value;
}