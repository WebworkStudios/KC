<?php


namespace Src\Queue\Connection;

use DateTime;
use DateTimeZone;
use PDO;
use PDOException;
use Src\Database\ConnectionManager;
use Src\Database\Enums\ConnectionMode;
use Src\Log\LoggerInterface;
use Src\Queue\Exception\QueueException;
use Src\Queue\Job;
use Src\Queue\Job\JobInterface;
use Throwable;

/**
 * MySQL-basierte Queue-Verbindung
 */
class MySQLConnection implements ConnectionInterface
{
    /** @var ConnectionManager Manager für Datenbankverbindungen */
    private ConnectionManager $connectionManager;

    /** @var string Name der Datenbankverbindung */
    private string $connectionName;

    /** @var string Name der Queue */
    private string $queueName;

    /** @var string Name der Tabelle für Jobs */
    private string $jobsTable;

    /** @var string Name der Tabelle für fehlgeschlagene Jobs */
    private string $failedJobsTable;

    /** @var string Name der Tabelle für wiederkehrende Jobs */
    private string $recurringJobsTable;

    /** @var LoggerInterface Logger für Queue-Operationen */
    private LoggerInterface $logger;

    /**
     * Erstellt eine neue MySQL Queue-Verbindung
     *
     * @param ConnectionManager $connectionManager Manager für Datenbankverbindungen
     * @param string $connectionName Name der Datenbankverbindung
     * @param string $queueName Name der Queue
     * @param string $jobsTable Name der Tabelle für Jobs
     * @param string $failedJobsTable Name der Tabelle für fehlgeschlagene Jobs
     * @param string $recurringJobsTable Name der Tabelle für wiederkehrende Jobs
     * @param LoggerInterface $logger Logger für Queue-Operationen
     */
    public function __construct(
        ConnectionManager $connectionManager,
        string            $connectionName,
        string            $queueName,
        string            $jobsTable,
        string            $failedJobsTable,
        string            $recurringJobsTable,
        LoggerInterface   $logger
    )
    {
        $this->connectionManager = $connectionManager;
        $this->connectionName = $connectionName;
        $this->queueName = $queueName;
        $this->jobsTable = $jobsTable;
        $this->failedJobsTable = $failedJobsTable;
        $this->recurringJobsTable = $recurringJobsTable;
        $this->logger = $logger;
    }

    /**
     * {@inheritDoc}
     */
    public function push(JobInterface $job, ?DateTime $executeAt = null, int $priority = 0): string
    {
        // Bei eindeutigen Jobs prüfen, ob bereits ein Job existiert
        if ($job->isUnique()) {
            try {
                $uniqueKey = $job->getUniqueKey();

                $connection = $this->getConnection();
                $stmt = $connection->prepare("
                    SELECT id FROM {$this->jobsTable}
                    WHERE queue = :queue
                    AND unique_key = :unique_key
                    AND (
                        failed_at IS NULL
                        OR TIMESTAMPDIFF(SECOND, failed_at, NOW()) < 86400
                    )
                    LIMIT 1
                ");

                $stmt->execute([
                    ':queue' => $this->queueName,
                    ':unique_key' => $uniqueKey
                ]);

                $existingJob = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingJob) {
                    $this->logger->debug("Eindeutiger Job bereits in Queue", [
                        'job_id' => $existingJob['id'],
                        'queue' => $this->queueName,
                        'unique_key' => $uniqueKey
                    ]);

                    return $existingJob['id'];
                }
            } catch (Throwable $e) {
                $this->logger->warning("Fehler beim Prüfen auf eindeutigen Job", [
                    'queue' => $this->queueName,
                    'exception' => $e->getMessage()
                ]);
                // Weitermachen und Job trotzdem hinzufügen
            }
        }

        $connection = $this->getConnection(true);

        // Job-Daten vorbereiten
        $id = $job->getId();
        $payload = json_encode($job->toArray());
        $now = new DateTime();
        $uniqueKey = $job->isUnique() ? $job->getUniqueKey() : null;

        try {
            $stmt = $connection->prepare("
                INSERT INTO {$this->jobsTable} (
                    id, queue, payload, priority, unique_key,
                    created_at, execute_at
                )
                VALUES (
                    :id, :queue, :payload, :priority, :unique_key,
                    :created_at, :execute_at
                )
            ");

            $stmt->execute([
                ':id' => $id,
                ':queue' => $this->queueName,
                ':payload' => $payload,
                ':priority' => $priority,
                ':unique_key' => $uniqueKey,
                ':created_at' => $now->format('Y-m-d H:i:s'),
                ':execute_at' => $executeAt ? $executeAt->format('Y-m-d H:i:s') : null
            ]);

            return $id;
        } catch (PDOException $e) {
            throw new QueueException(
                "Fehler beim Hinzufügen des Jobs zur Queue: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function pop(): ?Job
    {
        $connection = $this->getConnection(true);

        try {
            // Transaktion starten
            $connection->beginTransaction();

            // Nächsten ausführbaren Job holen
            $stmt = $connection->prepare("
                SELECT * FROM {$this->jobsTable}
                WHERE queue = :queue
                AND (execute_at IS NULL OR execute_at <= NOW())
                AND reserved_at IS NULL
                AND failed_at IS NULL
                ORDER BY priority DESC, created_at ASC
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([':queue' => $this->queueName]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$data) {
                $connection->rollBack();
                return null;
            }

            // Job als reserviert markieren
            $now = new DateTime();
            $stmt = $connection->prepare("
                UPDATE {$this->jobsTable}
                SET 
                    reserved_at = :reserved_at,
                    attempts = attempts + 1
                WHERE id = :id
            ");

            $stmt->execute([
                ':reserved_at' => $now->format('Y-m-d H:i:s'),
                ':id' => $data['id']
            ]);

            // Transaktion bestätigen
            $connection->commit();

            // Job-Objekt erstellen
            return $this->createJobFromData($data);
        } catch (PDOException $e) {
            // Transaktion zurückrollen
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            $this->logger->error("Fehler beim Abrufen des Jobs aus der Queue", [
                'queue' => $this->queueName,
                'exception' => $e->getMessage()
            ]);

            throw new QueueException(
                "Fehler beim Abrufen des Jobs aus der Queue: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $jobId): bool
    {
        $connection = $this->getConnection(true);

        try {
            $stmt = $connection->prepare("
                DELETE FROM {$this->jobsTable}
                WHERE id = :id AND queue = :queue
            ");

            $stmt->execute([
                ':id' => $jobId,
                ':queue' => $this->queueName
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Entfernen des Jobs aus der Queue", [
                'queue' => $this->queueName,
                'job_id' => $jobId,
                'exception' => $e->getMessage()
            ]);

            throw new QueueException(
                "Fehler beim Entfernen des Jobs aus der Queue: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function schedule(JobInterface $job, DateTime $executeAt, int $priority = 0): string
    {
        return $this->push($job, $executeAt, $priority);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsRecurring(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function registerRecurringJob(JobInterface $job, string $cron, int $priority = 0): string
    {
        $connection = $this->getConnection(true);

        try {
            $id = $job->getId();
            $name = $job->getName();
            $payload = json_encode($job->toArray());
            $now = new DateTime();

            // Prüfen, ob bereits ein wiederkehrender Job mit diesem Namen existiert
            $stmt = $connection->prepare("
                SELECT id FROM {$this->recurringJobsTable}
                WHERE queue = :queue AND name = :name
            ");

            $stmt->execute([
                ':queue' => $this->queueName,
                ':name' => $name
            ]);

            $existingJob = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingJob) {
                // Bestehenden Job aktualisieren
                $stmt = $connection->prepare("
                    UPDATE {$this->recurringJobsTable}
                    SET 
                        cron = :cron,
                        payload = :payload,
                        priority = :priority,
                        updated_at = :updated_at
                    WHERE id = :id
                ");

                $stmt->execute([
                    ':cron' => $cron,
                    ':payload' => $payload,
                    ':priority' => $priority,
                    ':updated_at' => $now->format('Y-m-d H:i:s'),
                    ':id' => $existingJob['id']
                ]);

                return $existingJob['id'];
            } else {
                // Neuen wiederkehrenden Job erstellen
                $stmt = $connection->prepare("
                    INSERT INTO {$this->recurringJobsTable} (
                        id, queue, name, cron, payload, priority,
                        created_at, updated_at
                    )
                    VALUES (
                        :id, :queue, :name, :cron, :payload, :priority,
                        :created_at, :updated_at
                    )
                ");

                $stmt->execute([
                    ':id' => $id,
                    ':queue' => $this->queueName,
                    ':name' => $name,
                    ':cron' => $cron,
                    ':payload' => $payload,
                    ':priority' => $priority,
                    ':created_at' => $now->format('Y-m-d H:i:s'),
                    ':updated_at' => $now->format('Y-m-d H:i:s')
                ]);

                return $id;
            }
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Registrieren des wiederkehrenden Jobs", [
                'queue' => $this->queueName,
                'job_name' => $job->getName(),
                'exception' => $e->getMessage()
            ]);

            throw new QueueException(
                "Fehler beim Registrieren des wiederkehrenden Jobs: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getStats(): array
    {
        $connection = $this->getConnection();

        try {
            $stats = [
                'pending' => 0,
                'reserved' => 0,
                'failed' => 0,
                'delayed' => 0,
                'done' => 0,
                'recurring' => 0
            ];

            // Anzahl ausstehender Jobs
            $stmt = $connection->prepare("
                SELECT COUNT(*) AS count FROM {$this->jobsTable}
                WHERE queue = :queue
                AND reserved_at IS NULL
                AND failed_at IS NULL
                AND (execute_at IS NULL OR execute_at <= NOW())
            ");

            $stmt->execute([':queue' => $this->queueName]);
            $stats['pending'] = (int)$stmt->fetchColumn();

            // Anzahl reservierter Jobs
            $stmt = $connection->prepare("
                SELECT COUNT(*) AS count FROM {$this->jobsTable}
                WHERE queue = :queue
                AND reserved_at IS NOT NULL
                AND failed_at IS NULL
            ");

            $stmt->execute([':queue' => $this->queueName]);
            $stats['reserved'] = (int)$stmt->fetchColumn();

            // Anzahl fehlgeschlagener Jobs
            $stmt = $connection->prepare("
                SELECT COUNT(*) AS count FROM {$this->jobsTable}
                WHERE queue = :queue
                AND failed_at IS NOT NULL
            ");

            $stmt->execute([':queue' => $this->queueName]);
            $stats['failed'] = (int)$stmt->fetchColumn();

            // Anzahl verzögerter Jobs
            $stmt = $connection->prepare("
                SELECT COUNT(*) AS count FROM {$this->jobsTable}
                WHERE queue = :queue
                AND reserved_at IS NULL
                AND failed_at IS NULL
                AND execute_at > NOW()
            ");

            $stmt->execute([':queue' => $this->queueName]);
            $stats['delayed'] = (int)$stmt->fetchColumn();

            // Anzahl erledigter Jobs (abgeschlossen ohne Fehler)
            $stmt = $connection->prepare("
                SELECT COUNT(*) AS count FROM {$this->jobsTable}
                WHERE queue = :queue
                AND last_executed_at IS NOT NULL
                AND failed_at IS NULL
                AND reserved_at IS NULL
            ");

            $stmt->execute([':queue' => $this->queueName]);
            $stats['done'] = (int)$stmt->fetchColumn();

            // Anzahl wiederkehrender Jobs
            $stmt = $connection->prepare("
                SELECT COUNT(*) AS count FROM {$this->recurringJobsTable}
                WHERE queue = :queue
            ");

            $stmt->execute([':queue' => $this->queueName]);
            $stats['recurring'] = (int)$stmt->fetchColumn();

            return $stats;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Abrufen der Queue-Statistiken", [
                'queue' => $this->queueName,
                'exception' => $e->getMessage()
            ]);

            throw new QueueException(
                "Fehler beim Abrufen der Queue-Statistiken: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function prune(int $maxAge): int
    {
        $connection = $this->getConnection(true);

        try {
            $cutoff = (new DateTime())->modify("-{$maxAge} seconds");

            $stmt = $connection->prepare("
                DELETE FROM {$this->jobsTable}
                WHERE queue = :queue
                AND (
                    (last_executed_at IS NOT NULL AND failed_at IS NULL AND last_executed_at < :cutoff)
                    OR
                    (failed_at IS NOT NULL AND failed_at < :cutoff)
                )
            ");

            $stmt->execute([
                ':queue' => $this->queueName,
                ':cutoff' => $cutoff->format('Y-m-d H:i:s')
            ]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Bereinigen der Queue", [
                'queue' => $this->queueName,
                'exception' => $e->getMessage()
            ]);

            throw new QueueException(
                "Fehler beim Bereinigen der Queue: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): int
    {
        $connection = $this->getConnection(true);

        try {
            $stmt = $connection->prepare("
                DELETE FROM {$this->jobsTable}
                WHERE queue = :queue
            ");

            $stmt->execute([':queue' => $this->queueName]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Leeren der Queue", [
                'queue' => $this->queueName,
                'exception' => $e->getMessage()
            ]);

            throw new QueueException(
                "Fehler beim Leeren der Queue: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hasFailedJobStorage(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function storeFailedJob(Job $job, Throwable $exception): bool
    {
        $connection = $this->getConnection(true);

        try {
            $data = $job->toArray();
            $now = new DateTime();

            $stmt = $connection->prepare("
                INSERT INTO {$this->failedJobsTable} (
                    id, queue, job_id, payload, exception, failed_at
                )
                VALUES (
                    :id, :queue, :job_id, :payload, :exception, :failed_at
                )
                ON DUPLICATE KEY UPDATE
                    payload = :payload,
                    exception = :exception,
                    failed_at = :failed_at
            ");

            $stmt->execute([
                ':id' => bin2hex(random_bytes(16)),
                ':queue' => $this->queueName,
                ':job_id' => $job->getId(),
                ':payload' => json_encode($data),
                ':exception' => json_encode([
                    'message' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString()
                ]),
                ':failed_at' => $now->format('Y-m-d H:i:s')
            ]);

            return true;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Speichern des fehlgeschlagenen Jobs", [
                'queue' => $this->queueName,
                'job_id' => $job->getId(),
                'exception' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getFailedJobs(int $limit, int $offset): array
    {
        $connection = $this->getConnection();

        try {
            $stmt = $connection->prepare("
                SELECT * FROM {$this->failedJobsTable}
                WHERE queue = :queue
                ORDER BY failed_at DESC
                LIMIT :limit OFFSET :offset
            ");

            $stmt->bindValue(':queue', $this->queueName, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $failedJobs = [];
            while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $payload = json_decode($data['payload'], true);
                $exception = json_decode($data['exception'], true);

                $failedJobs[] = [
                    'id' => $data['id'],
                    'job_id' => $data['job_id'],
                    'queue' => $data['queue'],
                    'payload' => $payload,
                    'exception' => $exception,
                    'failed_at' => $data['failed_at']
                ];
            }

            return $failedJobs;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Abrufen fehlgeschlagener Jobs", [
                'queue' => $this->queueName,
                'exception' => $e->getMessage()
            ]);

            throw new QueueException(
                "Fehler beim Abrufen fehlgeschlagener Jobs: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function retryFailedJob(string $jobId): bool
    {
        $connection = $this->getConnection(true);

        try {
            // Fehlgeschlagenen Job abrufen
            $stmt = $connection->prepare("
                SELECT * FROM {$this->failedJobsTable}
                WHERE id = :id AND queue = :queue
            ");

            $stmt->execute([
                ':id' => $jobId,
                ':queue' => $this->queueName
            ]);

            $failedJob = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$failedJob) {
                return false;
            }

            // Payload dekodieren
            $payload = json_decode($failedJob['payload'], true);

            if (empty($payload) || empty($payload['payload']['class'])) {
                $this->logger->warning("Ungültiger Payload für fehlgeschlagenen Job", [
                    'job_id' => $jobId,
                    'queue' => $this->queueName
                ]);

                return false;
            }

            // Job-Klasse laden
            $jobClass = $payload['payload']['class'];

            if (!class_exists($jobClass) || !is_subclass_of($jobClass, JobInterface::class)) {
                $this->logger->warning("Ungültige Job-Klasse für fehlgeschlagenen Job", [
                    'job_id' => $jobId,
                    'queue' => $this->queueName,
                    'class' => $jobClass
                ]);

                return false;
            }

            // Job-Objekt erstellen
            $job = $jobClass::fromArray($payload['payload']);

            // Job neu zur Queue hinzufügen
            $newJobId = $this->push($job);

            // Fehlgeschlagenen Job löschen
            $stmt = $connection->prepare("
                DELETE FROM {$this->failedJobsTable}
                WHERE id = :id AND queue = :queue
            ");

            $stmt->execute([
                ':id' => $jobId,
                ':queue' => $this->queueName
            ]);

            $this->logger->info("Fehlgeschlagener Job erneut zur Queue hinzugefügt", [
                'old_job_id' => $jobId,
                'new_job_id' => $newJobId,
                'queue' => $this->queueName
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim erneuten Ausführen des fehlgeschlagenen Jobs", [
                'job_id' => $jobId,
                'queue' => $this->queueName,
                'exception' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        // In dieser Implementierung nichts zu tun, da PDO-Verbindungen automatisch geschlossen werden
    }

    /**
     * Erstellt ein Job-Objekt aus Datenbankdaten
     *
     * @param array $data Datenbankdaten
     * @return Job Job-Objekt
     */
    private function createJobFromData(array $data): Job
    {
        $payload = json_decode($data['payload'], true);
        $attempts = (int)$data['attempts'];

        // Datumsfelder parsen
        $createdAt = !empty($data['created_at'])
            ? new DateTime($data['created_at'], new DateTimeZone('UTC'))
            : null;

        $executeAt = !empty($data['execute_at'])
            ? new DateTime($data['execute_at'], new DateTimeZone('UTC'))
            : null;

        $reservedAt = !empty($data['reserved_at'])
            ? new DateTime($data['reserved_at'], new DateTimeZone('UTC'))
            : null;

        $lastExecutedAt = !empty($data['last_executed_at'])
            ? new DateTime($data['last_executed_at'], new DateTimeZone('UTC'))
            : null;

        $failedAt = !empty($data['failed_at'])
            ? new DateTime($data['failed_at'], new DateTimeZone('UTC'))
            : null;

        // Job-Objekt erstellen
        $job = new Job(
            $data['id'],
            $data['queue'],
            $payload,
            $attempts,
            $createdAt,
            $executeAt,
            (int)$data['priority']
        );

        // Zusätzliche Informationen setzen
        if ($reservedAt) {
            $job->markAsReserved();
        }

        return $job;
    }

    /**
     * Holt eine PDO-Verbindung aus dem ConnectionManager
     *
     * @param bool $forWrite Ob eine Schreibverbindung benötigt wird
     * @return PDO PDO-Verbindung
     */
    private function getConnection(bool $forWrite = false): PDO
    {
        return $this->connectionManager->getConnection(
            $this->connectionName,
            $forWrite
        );
    }
}