<?php

namespace App\Services;

use Src\Container\Container;
use Src\Database\Anonymization\AnonymizationService;
use Src\Database\DatabaseFactory;
use Src\Database\QueryBuilder;
use Src\Log\LoggerInterface;

/**
 * Service für den Zugriff auf die Datenbank
 *
 * Bietet eine vereinfachte Schnittstelle zum Erstellen von QueryBuildern
 * und für häufige Datenbankoperationen.
 */
class DatabaseService
{
    /**
     * Container-Instanz
     *
     * @var Container
     */
    private Container $container;

    /**
     * Logger-Instanz
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Anonymisierungsservice-Instanz
     *
     * @var AnonymizationService|null
     */
    private ?AnonymizationService $anonymizationService = null;

    /**
     * Cache für QueryBuilder-Instanzen
     *
     * @var array<string, QueryBuilder>
     */
    private array $queryBuilderCache = [];

    /**
     * Konfiguration für den QueryBuilder
     *
     * @var array
     */
    private array $queryBuilderConfig;

    /**
     * Erstellt einen neuen DatabaseService
     *
     * @param Container $container Container-Instanz
     * @param LoggerInterface $logger Logger-Instanz
     */
    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;

        // Konfiguration aus Config-Klasse laden, falls vorhanden
        $config = $container->has('Config') ? $container->get('Config') : null;
        $this->queryBuilderConfig = $config?->get('database.query_builder', []) ?? [];
    }

    /**
     * Erstellt einen QueryBuilder für eine Tabelle
     *
     * @param string $table Tabellenname
     * @param string $connection Name der Datenbankverbindung
     * @param bool|null $anonymize Anonymisierung aktivieren
     * @param bool|null $cache Caching aktivieren
     * @return QueryBuilder QueryBuilder-Instanz
     */
    public function table(
        string $table,
        string $connection = 'main',
        ?bool  $anonymize = null,
        ?bool $cache = null
    ): QueryBuilder
    {
        // QueryBuilder aus Cache holen, falls verfügbar
        $cacheKey = "{$connection}.{$table}";
        if (isset($this->queryBuilderCache[$cacheKey])) {
            return $this->getConfiguredQueryBuilder(
                $this->queryBuilderCache[$cacheKey],
                $anonymize,
                $cache
            );
        }

        // Cache-Provider ermitteln, falls Caching aktiviert ist
        $cacheProvider = null;
        $enableCache = $cache ?? ($this->queryBuilderConfig['auto_cache'] ?? false);

        if ($enableCache && $this->container->has('Src\\Cache\\CacheInterface')) {
            $cacheProvider = $this->container->get('Src\\Cache\\CacheInterface');
        } elseif ($enableCache) {
            // Fallback auf ArrayCache, wenn kein Cache-Provider registriert ist
            $cacheProvider = DatabaseFactory::createArrayCache();
        }

        // QueryBuilder erstellen
        $queryBuilder = DatabaseFactory::createQueryBuilder(
            connectionName: $connection,
            table: $table,
            logger: $this->logger,
            cache: $cacheProvider
        );

        // Im Cache speichern
        $this->queryBuilderCache[$cacheKey] = $queryBuilder;

        // Mit Konfiguration anpassen und zurückgeben
        return $this->getConfiguredQueryBuilder($queryBuilder, $anonymize, $cache);
    }

    /**
     * Konfiguriert einen QueryBuilder basierend auf den übergebenen Optionen
     *
     * @param QueryBuilder $queryBuilder QueryBuilder-Instanz
     * @param bool|null $anonymize Anonymisierung aktivieren
     * @param bool|null $cache Caching aktivieren
     * @return QueryBuilder Konfigurierter QueryBuilder
     */
    private function getConfiguredQueryBuilder(
        QueryBuilder $queryBuilder,
        ?bool $anonymize,
        ?bool $cache
    ): QueryBuilder
    {
        // Anonymisierung aktivieren, falls gewünscht
        $enableAnonymize = $anonymize ?? ($this->queryBuilderConfig['auto_anonymize'] ?? false);

        if ($enableAnonymize) {
            $anonymizationService = $this->getAnonymizationService();
            $queryBuilder->anonymize(
                $this->queryBuilderConfig['anonymize_fields'] ?? [],
                $anonymizationService
            );
        }

        // Caching aktivieren, falls gewünscht
        $enableCache = $cache ?? ($this->queryBuilderConfig['auto_cache'] ?? false);
        $cacheTtl = $this->queryBuilderConfig['cache_ttl'] ?? 3600;

        if ($enableCache) {
            $queryBuilder->cache(null, $cacheTtl);
        }

        return $queryBuilder;
    }

    /**
     * Gibt den AnonymizationService zurück oder erstellt einen neuen
     *
     * @return AnonymizationService
     */
    private function getAnonymizationService(): AnonymizationService
    {
        if ($this->anonymizationService === null) {
            // Falls im Container registriert, von dort holen
            if ($this->container->has(AnonymizationService::class)) {
                $this->anonymizationService = $this->container->get(AnonymizationService::class);
            } else {
                // Ansonsten neu erstellen
                $this->anonymizationService = new AnonymizationService($this->logger);
            }
        }

        return $this->anonymizationService;
    }

    /**
     * Führt eine Raw-SQL-Abfrage aus
     *
     * @param string $sql SQL-Abfrage
     * @param array $bindings Parameter-Bindings
     * @param string $connection Name der Datenbankverbindung
     * @return array<array> Abfrageergebnisse
     */
    public function query(string $sql, array $bindings = [], string $connection = 'main'): array
    {
        $this->logger->debug('Führe Raw-SQL-Abfrage aus', [
            'sql' => $sql,
            'connection' => $connection
        ]);

        $connectionManager = DatabaseFactory::getConnectionManager($this->logger);
        $pdo = $connectionManager->getConnection($connection);

        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Führt eine Transaktion aus
     *
     * @param callable $callback Callback-Funktion, die innerhalb der Transaktion ausgeführt wird
     * @param string $connection Name der Datenbankverbindung
     * @return mixed Rückgabewert des Callbacks
     */
    public function transaction(callable $callback, string $connection = 'main'): mixed
    {
        return $this->table('', $connection)->transaction($callback);
    }
}