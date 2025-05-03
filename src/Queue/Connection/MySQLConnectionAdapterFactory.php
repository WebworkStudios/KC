<?php


namespace Src\Queue\Connection;

use Src\Container\Container;
use Src\Database\Connection;
use Src\Database\ConnectionManager;
use Src\Log\LoggerInterface;

/**
 * Factory für angepasste MySQL-basierte Queue-Verbindungen
 */
class MySQLConnectionAdapterFactory implements ConnectionFactoryInterface
{
    /** @var string Name der Datenbankverbindung */
    private string $connectionName;

    /** @var string Name der Tabelle für Jobs */
    private string $jobsTable;

    /** @var string Name der Tabelle für fehlgeschlagene Jobs */
    private string $failedJobsTable;

    /** @var string Name der Tabelle für wiederkehrende Jobs */
    private string $recurringJobsTable;

    /**
     * Erstellt eine neue MySQL Connection Factory
     *
     * @param string $connectionName Name der Datenbankverbindung
     * @param string $jobsTable Name der Tabelle für Jobs
     * @param string $failedJobsTable Name der Tabelle für fehlgeschlagene Jobs
     * @param string $recurringJobsTable Name der Tabelle für wiederkehrende Jobs
     */
    public function __construct(
        string $connectionName = 'default',
        string $jobsTable = 'queue_jobs',
        string $failedJobsTable = 'queue_failed_jobs',
        string $recurringJobsTable = 'queue_recurring_jobs'
    )
    {
        $this->connectionName = $connectionName;
        $this->jobsTable = $jobsTable;
        $this->failedJobsTable = $failedJobsTable;
        $this->recurringJobsTable = $recurringJobsTable;
    }

    /**
     * {@inheritDoc}
     */
    public function createConnection(
        string          $queueName,
        Container       $container,
        LoggerInterface $logger
    ): ConnectionInterface
    {
        // ConnectionManager aus dem Container holen
        $connectionManager = $container->get(ConnectionManager::class);

        // Passende Datenbankverbindung holen
        $connection = $connectionManager->getConnection($this->connectionName);

        return new MySQLConnectionAdapter(
            $connection,
            $queueName,
            $this->jobsTable,
            $this->failedJobsTable,
            $this->recurringJobsTable,
            $logger
        );
    }
}