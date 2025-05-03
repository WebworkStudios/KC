<?php

namespace Src\Queue\Connection;

use DateTime;
use DateTimeZone;
use PDO;
use PDOException;
use Src\Database\Enums\OrderDirection;
use Src\Database\Enums\SqlOperator;
use Src\Database\QueryBuilder;
use Src\Log\LoggerInterface;
use Src\Queue\Exception\QueueException;
use Src\Queue\Job;
use Src\Queue\Job\JobInterface;
use Throwable;

/**
 * Angepasste MySQL-basierte Queue-Verbindung die mit dem QueryBuilder arbeitet
 */
class MySQLConnectionAdapter implements ConnectionInterface
{
    /** @var QueryBuilder QueryBuilder-Instanz */
    private QueryBuilder $queryBuilder;

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
     * Erstellt eine neue MySQL Queue-Verbindung mit QueryBuilder
     *
     * @param QueryBuilder $queryBuilder QueryBuilder-Instanz
     * @param string $queueName Name der Queue
     * @param string $jobsTable Name der Tabelle für Jobs
     * @param string $failedJobsTable Name der Tabelle für fehlgeschlagene Jobs
     * @param string $recurringJobsTable Name der Tabelle für wiederkehrende Jobs
     * @param LoggerInterface $logger Logger für Queue-Operationen
     */
    public function __construct(
        QueryBuilder    $queryBuilder,
        string          $queueName,
        string          $jobsTable,
        string          $failedJobsTable,
        string          $recurringJobsTable,
        LoggerInterface $logger
    )
    {
        $this->queryBuilder = $queryBuilder;
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

                // Existierenden eindeutigen Job suchen
                $existingJob = $this->queryBuilder
                    ->table($this->jobsTable)
                    ->select(['id'])
                    ->where('queue', $this->queueName)
                    ->where('unique_key', $uniqueKey)
                    ->whereRaw('(failed_at IS NULL OR TIMESTAMPDIFF(SECOND, failed_at, NOW()) < 86400)')
                    ->limit(1)
                    ->first();

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

        // Job-Daten vorbereiten
        $id = $job->getId();
        $payload = json_encode($job->toArray());
        $now = new DateTime();
        $uniqueKey = $job->isUnique() ? $job->getUniqueKey() : null;

        try {
            // Job einfügen
            $this->queryBuilder
                ->table($this->jobsTable)
                ->insert([
                    'id' => $id,
                    'queue' => $this->queueName,
                    'payload' => $payload,
                    'priority' => $priority,
                    'unique_key' => $uniqueKey,
                    'created_at' => $now->format('Y-m-d H:i:s'),
                    'execute_at' => $executeAt ? $executeAt->format('Y-m-d H:i:s') : null
                ]);

            return $id;
        } catch (Throwable $e) {
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
        // Für den pop-Vorgang müssen wir mit Transaction arbeiten und "FOR UPDATE" verwenden
        // Da dies nicht direkt über den QueryBuilder geht, verwenden wir transaction() mit Closure
        $job = null;

        try {
            $this->queryBuilder->transaction(function ($query) use (&$job) {
                // Direkten PDO-Zugriff für "SELECT ... FOR UPDATE"
                $connection = $this->queryBuilder->getConnection($this->queryBuilder->getConnectionName(), true);

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
                    return null; // Keine Jobs gefunden
                }

                // Job als reserviert markieren
                $now = new DateTime();

                $query->table($this->jobsTable)
                    ->where('id', $data['id'])
                    ->update([
                        'reserved_at' => $now->format('Y-m-d H:i:s'),
                        'attempts' => (int)$data['attempts'] + 1
                    ]);

                // Job-Objekt erstellen
                $job = $this->createJobFromData($data);
            });

            return $job;
        } catch (Throwable $e) {
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
        try {
            $affected = $this->queryBuilder
                ->table($this->jobsTable)
                ->where('id', $jobId)
                ->where('queue', $this->queueName)
                ->delete();

            return $affected > 0;
        } catch (Throwable $e) {
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
        try {
            $id = $job->getId();
            $name = $job->getName();
            $payload = json_encode($job->toArray());
            $now = new DateTime();

            // Prüfen, ob bereits ein wiederkehrender Job mit diesem Namen existiert
            $existingJob = $this->queryBuilder
                ->table($this->recurringJobsTable)
                ->select(['id'])
                ->where('queue', $this->queueName)
                ->where('name', $name)
                ->first();

            if ($existingJob) {
                // Bestehenden Job aktualisieren
                $this->queryBuilder
                    ->table($this->recurringJobsTable)
                    ->where('id', $existingJob['id'])
                    ->update([
                        'cron' => $cron,
                        'payload' => $payload,
                        'priority' => $priority,
                        'updated_at' => $now->format('Y-m-d H:i:s')
                    ]);

                return $existingJob['id'];
            } else {
                // Neuen wiederkehrenden Job erstellen
                $this->queryBuilder
                    ->table($this->recurringJobsTable)
                    ->insert([
                        'id' => $id,
                        'queue' => $this->queueName,
                        'name' => $name,
                        'cron' => $cron,
                        'payload' => $payload,
                        'priority' => $priority,
                        'created_at' => $now->format('Y-m-d H:i:s'),
                        'updated_at' => $now->format('Y-m-d H:i:s')
                    ]);

                return $id;
            }
        } catch (Throwable $e) {
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
            $stats['pending'] = (int)$this->queryBuilder
                ->table($this->jobsTable)
                ->where('queue', $this->queueName)
                ->whereNull('reserved_at')
                ->whereNull('failed_at')
                ->whereRaw('(execute_at IS NULL OR execute_at <= NOW())')
                ->count();

            // Anzahl reservierter Jobs
            $stats['reserved'] = (int)$this->queryBuilder
                ->table($this->jobsTable)
                ->where('queue', $this->queueName)
                ->whereNotNull('reserved_at')
                ->whereNull('failed_at')
                ->count();

            // Anzahl fehlgeschlagener Jobs
            $stats['failed'] = (int)$this->queryBuilder
                ->table($this->jobsTable)
                ->where('queue', $this->queueName)
                ->whereNotNull('failed_at')
                ->count();

            // Anzahl verzögerter Jobs
            $stats['delayed'] = (int)$this->queryBuilder
                ->table($this->jobsTable)
                ->where('queue', $this->queueName)
                ->whereNull('reserved_at')
                ->whereNull('failed_at')
                ->whereRaw('execute_at > NOW()')
                ->count();

            // Anzahl erledigter Jobs
            $stats['done'] = (int)$this->queryBuilder
                ->table($this->jobsTable)
                ->where('queue', $this->queueName)
                ->whereNotNull('last_executed_at')
                ->whereNull('failed_at')
                ->whereNull('reserved_at')
                ->count();

            // Anzahl wiederkehrender Jobs
            $stats['recurring'] = (int)$this->queryBuilder
                ->table($this->recurringJobsTable)
                ->where('queue', $this->queueName)
                ->count();

            return $stats;
        } catch (Throwable $e) {
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
        try {
            $cutoff = (new DateTime())->modify("-{$maxAge} seconds");
            $cutoffStr = $cutoff->format('Y-m-d H:i:s');

            $affected = $this->queryBuilder
                ->table($this->jobsTable)
                ->where('queue', $this->queueName)
                ->whereRaw('(
                    (last_executed_at IS NOT NULL AND failed_at IS NULL AND last_executed_at < ?) 
                    OR 
                    (failed_at IS NOT NULL AND failed_at < ?)
                )', [$cutoffStr, $cutoffStr])
                ->delete();

            return $affected;
        } catch (Throwable $e) {
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
        try {
            $affected = $this->queryBuilder
                ->table($this->jobsTable)
                ->where('queue', $this->queueName)
                ->delete();

            return $affected;
        } catch (Throwable $e) {
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
        try {
            $data = $job->toArray();
            $now = new DateTime();
            $id = bin2hex(random_bytes(16));

            // Direkten PDO-Zugriff für "ON DUPLICATE KEY UPDATE"
            $connection = $this->queryBuilder->getConnection($this->queryBuilder->getConnectionName(), true);

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
                ':id' => $id,
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
        } catch (Throwable $e) {
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
        try {
            $results = $this->queryBuilder
                ->table($this->failedJobsTable)
                ->where('queue', $this->queueName)
                ->orderBy('failed_at', OrderDirection::DESC)
                ->limit($limit)
                ->offset($offset)
                ->get();

            $failedJobs = [];
            foreach ($results as $data) {
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
        } catch (Throwable $e) {
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
        try {
            // Fehlgeschlagenen Job abrufen
            $failedJob = $this->queryBuilder
                ->table($this->failedJobsTable)
                ->where('id', $jobId)
                ->where('queue', $this->queueName)
                ->first();

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
            $this->queryBuilder
                ->table($this->failedJobsTable)
                ->where('id', $jobId)
                ->where('queue', $this->queueName)
                ->delete();

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
        // In dieser Implementierung nichts zu tun, da die QueryBuilder-Verbindung vom Framework verwaltet wird
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
}