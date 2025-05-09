<?php


namespace Src\Queue\Connection;

use Src\Container\Container;
use Src\Database\DatabaseFactory;
use Src\Log\LoggerInterface;

/**
 * Factory für MySQL-basierte Queue-Verbindungen mit QueryBuilder-Integration
 */
class MySQLQueryBuilderAdapterFactory implements ConnectionFactoryInterface
{
    /** @var string Name der Datenbankverbindung */
    private string $connectionName;

    /** @var string Name der Tabelle für Jobs */
    private string $jobsTable;

    /** @var string Name der Tabelle für fehlgeschlagene Jobs */
    private string $failedJobsTable;

    /** @var string Name der Tabelle für wiederkehrende Jobs */
    private string $recurringJobsTable;

    /** @var string|null Name des Cache-Providers für den QueryBuilder */
    private ?string $cacheProvider;

    /**
     * Erstellt eine neue MySQL Connection Factory mit QueryBuilder-Integration
     *
     * @param string $connectionName Name der Datenbankverbindung
     * @param string $jobsTable Name der Tabelle für Jobs
     * @param string $failedJobsTable Name der Tabelle für fehlgeschlagene Jobs
     * @param string $recurringJobsTable Name der Tabelle für wiederkehrende Jobs
     * @param string|null $cacheProvider Name des Cache-Providers für den QueryBuilder
     */
    public function __construct(
        string  $connectionName = 'default',
        string  $jobsTable = 'queue_jobs',
        string  $failedJobsTable = 'queue_failed_jobs',
        string  $recurringJobsTable = 'queue_recurring_jobs',
        ?string $cacheProvider = null
    )
    {
        $this->connectionName = $connectionName;
        $this->jobsTable = $jobsTable;
        $this->failedJobsTable = $failedJobsTable;
        $this->recurringJobsTable = $recurringJobsTable;
        $this->cacheProvider = $cacheProvider;
    }

    public function createConnection(
        string          $queueName,
        Container       $container,
        LoggerInterface $logger
    ): ConnectionInterface
    {
        // QueryBuilder für die Verbindung erstellen
        $queryBuilder = DatabaseFactory::createQueryBuilder(
            $this->connectionName,
            null,
            $logger,
            $this->cacheProvider
        );

        return new MySQLQueryBuilderAdapter(
            $queryBuilder,
            $queueName,
            $logger,
            $this->jobsTable,
            $this->failedJobsTable,
            $this->recurringJobsTable
        );
    }
}