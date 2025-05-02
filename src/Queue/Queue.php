<?php


namespace Src\Queue;

use DateInterval;
use DateTime;
use Exception;
use Src\Container\Container;
use Src\Log\LoggerInterface;
use Src\Queue\Connection\ConnectionInterface;
use Src\Queue\Exception\JobException;
use Src\Queue\Exception\QueueException;
use Src\Queue\Job\JobInterface;
use Src\Queue\Storage\StorageInterface;
use Throwable;

/**
 * Hauptklasse des Queue-Systems
 *
 * Verwaltet Jobs und deren Ausführung in verschiedenen Queues
 */
class Queue
{
    /** @var array<string, QueueConfig> Konfigurationen für verschiedene Queues */
    private array $queueConfigs = [];

    /** @var array<string, ConnectionInterface> Aktive Verbindungen zu Queue-Backends */
    private array $connections = [];

    /** @var Container DI-Container für das Auflösen von Job-Klassen */
    private readonly Container $container;

    /** @var LoggerInterface Logger für Queue-Operationen */
    private readonly LoggerInterface $logger;

    /** @var array Statistiken über verarbeitete Jobs */
    private array $stats = [
        'pushed' => 0,
        'processed' => 0,
        'failed' => 0,
        'retried' => 0
    ];

    /**
     * Erstellt eine neue Queue-Instanz
     *
     * @param Container $container DI-Container für das Auflösen von Job-Klassen
     * @param LoggerInterface $logger Logger für Queue-Operationen
     */
    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Registriert eine neue Queue-Konfiguration
     *
     * @param string $name Name der Queue (z.B. 'emails', 'exports', 'default')
     * @param QueueConfig $config Queue-Konfiguration
     * @return self
     */
    public function registerQueue(string $name, QueueConfig $config): self
    {
        $this->queueConfigs[$name] = $config;
        $this->logger->info("Queue registriert", ['queue' => $name]);
        return $this;
    }

    /**
     * Prüft, ob eine Queue existiert
     *
     * @param string $name Name der Queue
     * @return bool True, wenn die Queue existiert
     */
    public function hasQueue(string $name): bool
    {
        return isset($this->queueConfigs[$name]);
    }

    /**
     * Gibt alle registrierten Queue-Namen zurück
     *
     * @return array<string> Array mit Queue-Namen
     */
    public function getQueueNames(): array
    {
        return array_keys($this->queueConfigs);
    }

    /**
     * Fügt einen Job zu einer Queue hinzu
     *
     * @param string $queueName Name der Queue
     * @param JobInterface $job Zu verarbeitender Job
     * @param DateInterval|int|null $delay Optionale Verzögerung vor der Ausführung (in Sekunden oder als DateInterval)
     * @param int|null $priority Optionale Priorität (höherer Wert = höhere Priorität)
     * @return string Job-ID
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function push(
        string                $queueName,
        JobInterface          $job,
        DateInterval|int|null $delay = null,
        ?int                  $priority = null
    ): string
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        try {
            $connection = $this->getConnection($queueName);

            // Verzögerung verarbeiten
            $executeAt = null;
            if ($delay !== null) {
                $executeAt = new DateTime();
                if (is_int($delay)) {
                    $executeAt->modify("+$delay seconds");
                } else {
                    $executeAt->add($delay);
                }
            }

            // Priorität setzen oder Standardwert verwenden
            $priority = $priority ?? $this->queueConfigs[$queueName]->getDefaultPriority();

            // Job zur Queue hinzufügen
            $jobId = $connection->push($job, $executeAt, $priority);

            $this->stats['pushed']++;

            $this->logger->info("Job zur Queue hinzugefügt", [
                'queue' => $queueName,
                'job_id' => $jobId,
                'job_class' => get_class($job),
                'delay' => $delay !== null ? ($executeAt?->format('Y-m-d H:i:s') ?? '') : 'none',
                'priority' => $priority
            ]);

            return $jobId;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Hinzufügen des Jobs zur Queue", [
                'queue' => $queueName,
                'job_class' => get_class($job),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new QueueException(
                "Fehler beim Hinzufügen des Jobs zur Queue '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Fügt einen Job verzögert zu einer Queue hinzu
     *
     * @param string $queueName Name der Queue
     * @param JobInterface $job Zu verarbeitender Job
     * @param DateInterval|int $delay Verzögerung vor der Ausführung (in Sekunden oder als DateInterval)
     * @param int|null $priority Optionale Priorität (höherer Wert = höhere Priorität)
     * @return string Job-ID
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function later(
        string           $queueName,
        JobInterface     $job,
        DateInterval|int $delay,
        ?int             $priority = null
    ): string
    {
        return $this->push($queueName, $job, $delay, $priority);
    }

    /**
     * Holt den nächsten Job aus einer Queue
     *
     * @param string $queueName Name der Queue
     * @return Job|null Job-Objekt oder null, wenn keine Jobs verfügbar sind
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function pop(string $queueName): ?Job
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        try {
            $connection = $this->getConnection($queueName);
            $job = $connection->pop();

            if ($job !== null) {
                $this->logger->debug("Job aus Queue geholt", [
                    'queue' => $queueName,
                    'job_id' => $job->getId(),
                    'job_class' => $job->getJob() ? get_class($job->getJob()) : 'unknown'
                ]);
            }

            return $job;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Abrufen des Jobs aus der Queue", [
                'queue' => $queueName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new QueueException(
                "Fehler beim Abrufen des Jobs aus Queue '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Verarbeitet einen Job
     *
     * @param Job $job Zu verarbeitender Job
     * @return bool True, wenn der Job erfolgreich verarbeitet wurde
     */
    public function process(Job $job): bool
    {
        $jobId = $job->getId();
        $jobClass = $job->getJob() ? get_class($job->getJob()) : 'unknown';
        $queueName = $job->getQueue();

        $this->logger->info("Verarbeite Job", [
            'job_id' => $jobId,
            'job_class' => $jobClass,
            'queue' => $queueName,
            'attempts' => $job->getAttempts()
        ]);

        try {
            // Job-Objekt laden, falls noch nicht geschehen
            $payload = $job->getJob();
            if ($payload === null) {
                throw new JobException("Job-Payload konnte nicht geladen werden");
            }

            // Job ausführen
            $result = $payload->handle();

            // Job als verarbeitet markieren
            $job->markAsComplete();

            $this->stats['processed']++;

            $this->logger->info("Job erfolgreich verarbeitet", [
                'job_id' => $jobId,
                'job_class' => $jobClass,
                'queue' => $queueName,
                'result' => $result
            ]);

            return true;
        } catch (Throwable $e) {
            $this->stats['failed']++;

            $this->logger->error("Fehler bei der Job-Verarbeitung", [
                'job_id' => $jobId,
                'job_class' => $jobClass,
                'queue' => $queueName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Maximale Wiederholungsversuche prüfen
            $maxRetries = $this->getMaxRetries($queueName);

            if ($job->getAttempts() < $maxRetries) {
                // Job erneut zur Queue hinzufügen mit Verzögerung
                $this->retryJob($job, $e);
                return false;
            }

            // Job als fehlgeschlagen markieren
            $job->markAsFailed($e);

            // Fehlgeschlagene Jobs in separater Queue/Tabelle speichern
            $this->storeFailedJob($job, $e);

            return false;
        }
    }

    /**
     * Führt einen fehlgeschlagenen Job erneut aus
     *
     * @param Job $job Fehlgeschlagener Job
     * @param Throwable|null $exception Aufgetretene Exception
     * @return bool True, wenn der Job zur erneuten Ausführung eingeplant wurde
     */
    private function retryJob(Job $job, ?Throwable $exception = null): bool
    {
        $retryDelay = $this->calculateRetryDelay($job);

        try {
            $job->markForRetry($retryDelay);

            $this->stats['retried']++;

            $this->logger->info("Job zur erneuten Ausführung eingeplant", [
                'job_id' => $job->getId(),
                'job_class' => $job->getJob() ? get_class($job->getJob()) : 'unknown',
                'queue' => $job->getQueue(),
                'attempts' => $job->getAttempts(),
                'retry_delay' => $retryDelay,
                'next_execution' => (new DateTime())->modify("+{$retryDelay} seconds")->format('Y-m-d H:i:s'),
                'error' => $exception ? $exception->getMessage() : 'unknown error'
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim erneuten Einplanen des Jobs", [
                'job_id' => $job->getId(),
                'queue' => $job->getQueue(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Job als endgültig fehlgeschlagen markieren
            $job->markAsFailed($exception ?? $e);

            // Fehlgeschlagenen Job speichern
            $this->storeFailedJob($job, $exception ?? $e);

            return false;
        }
    }

    /**
     * Berechnet die Verzögerung für einen erneuten Versuch basierend auf der Anzahl der Versuche
     *
     * @param Job $job Job-Objekt
     * @return int Verzögerung in Sekunden
     */
    private function calculateRetryDelay(Job $job): int
    {
        $attempts = $job->getAttempts();
        $queueName = $job->getQueue();

        if (isset($this->queueConfigs[$queueName])) {
            $config = $this->queueConfigs[$queueName];
            $retryStrategy = $config->getRetryStrategy();

            return match ($retryStrategy) {
                RetryStrategy::FIXED => $config->getRetryDelay(),
                RetryStrategy::LINEAR => $attempts * $config->getRetryDelay(),
                RetryStrategy::EXPONENTIAL => (2 ** ($attempts - 1)) * $config->getRetryDelay(),
                default => $config->getRetryDelay()
            };
        }

        // Fallback: Exponentielles Backoff mit 5 Sekunden Basis
        return (2 ** ($attempts - 1)) * 5;
    }

    /**
     * Speichert einen fehlgeschlagenen Job
     *
     * @param Job $job Fehlgeschlagener Job
     * @param Throwable $exception Aufgetretene Exception
     * @return void
     */
    private function storeFailedJob(Job $job, Throwable $exception): void
    {
        try {
            $queueName = $job->getQueue();

            if (isset($this->queueConfigs[$queueName])) {
                $connection = $this->getConnection($queueName);

                if ($connection->hasFailedJobStorage()) {
                    $connection->storeFailedJob($job, $exception);

                    $this->logger->info("Fehlgeschlagener Job gespeichert", [
                        'job_id' => $job->getId(),
                        'queue' => $queueName
                    ]);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Speichern des fehlgeschlagenen Jobs", [
                'job_id' => $job->getId(),
                'queue' => $job->getQueue(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Maximale Anzahl an Wiederholungsversuchen für eine Queue ermitteln
     *
     * @param string $queueName Name der Queue
     * @return int Maximale Anzahl an Wiederholungsversuchen
     */
    private function getMaxRetries(string $queueName): int
    {
        if (isset($this->queueConfigs[$queueName])) {
            return $this->queueConfigs[$queueName]->getMaxRetries();
        }

        // Standardwert
        return 3;
    }

    /**
     * Gibt eine Verbindung zu einer Queue zurück
     *
     * @param string $queueName Name der Queue
     * @return ConnectionInterface Queue-Verbindung
     * @throws QueueException Bei Fehlern mit der Queue-Verbindung
     */
    private function getConnection(string $queueName): ConnectionInterface
    {
        if (!isset($this->queueConfigs[$queueName])) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        // Prüfen, ob bereits eine Verbindung existiert
        if (isset($this->connections[$queueName])) {
            return $this->connections[$queueName];
        }

        $config = $this->queueConfigs[$queueName];
        $connectionFactory = $config->getConnectionFactory();

        try {
            $connection = $connectionFactory->createConnection($queueName, $this->container, $this->logger);
            $this->connections[$queueName] = $connection;

            return $connection;
        } catch (Throwable $e) {
            throw new QueueException(
                "Fehler beim Erstellen der Queue-Verbindung für '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Plant einen Job für eine bestimmte Zeit ein
     *
     * @param string $queueName Name der Queue
     * @param JobInterface $job Zu verarbeitender Job
     * @param DateTime $dateTime Zeitpunkt der Ausführung
     * @param int|null $priority Optionale Priorität (höherer Wert = höhere Priorität)
     * @return string Job-ID
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function schedule(
        string       $queueName,
        JobInterface $job,
        DateTime     $dateTime,
        ?int         $priority = null
    ): string
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        try {
            $connection = $this->getConnection($queueName);

            // Priorität setzen oder Standardwert verwenden
            $priority = $priority ?? $this->queueConfigs[$queueName]->getDefaultPriority();

            // Job zur Queue hinzufügen
            $jobId = $connection->schedule($job, $dateTime, $priority);

            $this->stats['pushed']++;

            $this->logger->info("Job für spätere Ausführung eingeplant", [
                'queue' => $queueName,
                'job_id' => $jobId,
                'job_class' => get_class($job),
                'scheduled_at' => $dateTime->format('Y-m-d H:i:s'),
                'priority' => $priority
            ]);

            return $jobId;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Einplanen des Jobs", [
                'queue' => $queueName,
                'job_class' => get_class($job),
                'scheduled_at' => $dateTime->format('Y-m-d H:i:s'),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new QueueException(
                "Fehler beim Einplanen des Jobs in Queue '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Führt einen wiederkehrenden Job aus
     *
     * @param string $queueName Name der Queue
     * @param JobInterface $job Zu verarbeitender Job
     * @param string $cron Cron-Expression für die Wiederholung
     * @param int|null $priority Optionale Priorität (höherer Wert = höhere Priorität)
     * @return string Job-ID
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function recurring(
        string       $queueName,
        JobInterface $job,
        string       $cron,
        ?int         $priority = null
    ): string
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        try {
            $connection = $this->getConnection($queueName);

            // Prüfen, ob die Verbindung wiederkehrende Jobs unterstützt
            if (!$connection->supportsRecurring()) {
                throw new QueueException(
                    "Die Queue-Verbindung für '$queueName' unterstützt keine wiederkehrenden Jobs"
                );
            }

            // Priorität setzen oder Standardwert verwenden
            $priority = $priority ?? $this->queueConfigs[$queueName]->getDefaultPriority();

            // Wiederkehrenden Job registrieren
            $jobId = $connection->registerRecurringJob($job, $cron, $priority);

            $this->logger->info("Wiederkehrender Job registriert", [
                'queue' => $queueName,
                'job_id' => $jobId,
                'job_class' => get_class($job),
                'cron' => $cron,
                'priority' => $priority
            ]);

            return $jobId;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Registrieren des wiederkehrenden Jobs", [
                'queue' => $queueName,
                'job_class' => get_class($job),
                'cron' => $cron,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new QueueException(
                "Fehler beim Registrieren des wiederkehrenden Jobs in Queue '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Entfernt einen Job aus der Queue
     *
     * @param string $queueName Name der Queue
     * @param string $jobId ID des zu entfernenden Jobs
     * @return bool True, wenn der Job erfolgreich entfernt wurde
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function remove(string $queueName, string $jobId): bool
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        try {
            $connection = $this->getConnection($queueName);
            $result = $connection->remove($jobId);

            if ($result) {
                $this->logger->info("Job aus Queue entfernt", [
                    'queue' => $queueName,
                    'job_id' => $jobId
                ]);
            } else {
                $this->logger->notice("Job konnte nicht aus Queue entfernt werden", [
                    'queue' => $queueName,
                    'job_id' => $jobId
                ]);
            }

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Entfernen des Jobs aus der Queue", [
                'queue' => $queueName,
                'job_id' => $jobId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new QueueException(
                "Fehler beim Entfernen des Jobs aus Queue '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Gibt Statistiken über die Queue zurück
     *
     * @param string|null $queueName Optionaler Queue-Name für spezifische Statistiken
     * @return array Statistik-Daten
     */
    public function getStats(?string $queueName = null): array
    {
        $stats = $this->stats;

        // Spezifische Queue-Statistiken hinzufügen
        if ($queueName !== null && $this->hasQueue($queueName)) {
            try {
                $connection = $this->getConnection($queueName);
                $queueStats = $connection->getStats();

                $stats['queue'] = [
                    'name' => $queueName,
                    'pending' => $queueStats['pending'] ?? 0,
                    'reserved' => $queueStats['reserved'] ?? 0,
                    'failed' => $queueStats['failed'] ?? 0,
                    'delayed' => $queueStats['delayed'] ?? 0,
                    'done' => $queueStats['done'] ?? 0
                ];
            } catch (Throwable $e) {
                $this->logger->warning("Fehler beim Abrufen der Queue-Statistiken", [
                    'queue' => $queueName,
                    'exception' => $e->getMessage()
                ]);

                $stats['queue'] = [
                    'name' => $queueName,
                    'error' => 'Statistiken konnten nicht abgerufen werden'
                ];
            }
        }

        return $stats;
    }

    /**
     * Bereinigt alte verarbeitete Jobs aus der Queue
     *
     * @param string $queueName Name der Queue
     * @param int $maxAge Maximales Alter in Sekunden
     * @return int Anzahl der bereinigten Jobs
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function prune(string $queueName, int $maxAge = 604800): int
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        try {
            $connection = $this->getConnection($queueName);
            $count = $connection->prune($maxAge);

            $this->logger->info("Alte Jobs bereinigt", [
                'queue' => $queueName,
                'count' => $count,
                'max_age' => $maxAge
            ]);

            return $count;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Bereinigen der Queue", [
                'queue' => $queueName,
                'max_age' => $maxAge,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new QueueException(
                "Fehler beim Bereinigen der Queue '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Leert eine Queue vollständig
     *
     * @param string $queueName Name der Queue
     * @return int Anzahl der entfernten Jobs
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function clear(string $queueName): int
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        try {
            $connection = $this->getConnection($queueName);
            $count = $connection->clear();

            $this->logger->warning("Queue geleert", [
                'queue' => $queueName,
                'count' => $count
            ]);

            return $count;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Leeren der Queue", [
                'queue' => $queueName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new QueueException(
                "Fehler beim Leeren der Queue '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Führt erneut einen fehlgeschlagenen Job aus
     *
     * @param string $queueName Name der Queue
     * @param string $jobId ID des fehlgeschlagenen Jobs
     * @return bool True, wenn der Job erfolgreich erneut zur Queue hinzugefügt wurde
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function retry(string $queueName, string $jobId): bool
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        try {
            $connection = $this->getConnection($queueName);

            if (!$connection->hasFailedJobStorage()) {
                throw new QueueException(
                    "Die Queue-Verbindung für '$queueName' unterstützt keine Speicherung fehlgeschlagener Jobs"
                );
            }

            $result = $connection->retryFailedJob($jobId);

            if ($result) {
                $this->logger->info("Fehlgeschlagener Job erneut zur Queue hinzugefügt", [
                    'queue' => $queueName,
                    'job_id' => $jobId
                ]);

                $this->stats['retried']++;
            } else {
                $this->logger->notice("Fehlgeschlagener Job konnte nicht erneut zur Queue hinzugefügt werden", [
                    'queue' => $queueName,
                    'job_id' => $jobId
                ]);
            }

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim erneuten Ausführen des fehlgeschlagenen Jobs", [
                'queue' => $queueName,
                'job_id' => $jobId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new QueueException(
                "Fehler beim erneuten Ausführen des fehlgeschlagenen Jobs aus Queue '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Gibt fehlgeschlagene Jobs zurück
     *
     * @param string $queueName Name der Queue
     * @param int $limit Maximale Anzahl der zurückgegebenen Jobs
     * @param int $offset Offset für Pagination
     * @return array Fehlgeschlagene Jobs
     * @throws QueueException Bei Fehlern mit der Queue
     */
    public function getFailedJobs(string $queueName, int $limit = 10, int $offset = 0): array
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' nicht gefunden");
        }

        try {
            $connection = $this->getConnection($queueName);

            if (!$connection->hasFailedJobStorage()) {
                return [];
            }

            return $connection->getFailedJobs($limit, $offset);
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Abrufen fehlgeschlagener Jobs", [
                'queue' => $queueName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new QueueException(
                "Fehler beim Abrufen fehlgeschlagener Jobs aus Queue '$queueName': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Schließt alle aktiven Verbindungen
     */
    public function closeAll(): void
    {
        foreach ($this->connections as $name => $connection) {
            try {
                $connection->close();
                $this->logger->debug("Queue-Verbindung geschlossen", ['queue' => $name]);
            } catch (Throwable $e) {
                $this->logger->warning("Fehler beim Schließen der Queue-Verbindung", [
                    'queue' => $name,
                    'exception' => $e->getMessage()
                ]);
            }
        }

        $this->connections = [];
    }
}