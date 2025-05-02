<?php


namespace Src\Queue;

use DateTime;
use Exception;
use Src\Log\LoggerInterface;
use Src\Queue\Connection\ConnectionInterface;
use Src\Queue\Exception\QueueException;
use Src\Queue\Job\JobInterface;
use Throwable;

/**
 * Scheduler für wiederkehrende und geplante Jobs
 */
class Scheduler
{
    /** @var Queue Queue-Service */
    private Queue $queue;

    /** @var LoggerInterface Logger */
    private LoggerInterface $logger;

    /** @var CronParser Parser für Cron-Ausdrücke */
    private CronParser $cronParser;

    /** @var array Liste der definierten wiederkehrenden Jobs */
    private array $recurringJobs = [];

    /**
     * Erstellt einen neuen Scheduler
     *
     * @param Queue $queue Queue-Service
     * @param LoggerInterface $logger Logger
     */
    public function __construct(Queue $queue, LoggerInterface $logger)
    {
        $this->queue = $queue;
        $this->logger = $logger;
        $this->cronParser = new CronParser();
    }

    /**
     * Definiert einen wiederkehrenden Job
     *
     * @param string $name Eindeutiger Name für den Job
     * @param string $queueName Name der Queue
     * @param JobInterface $job Job-Objekt
     * @param string $cron Cron-Expression für die Wiederholung
     * @param int|null $priority Optionale Priorität
     * @return string Job-ID
     */
    public function scheduleRecurring(
        string       $name,
        string       $queueName,
        JobInterface $job,
        string       $cron,
        ?int         $priority = null
    ): string
    {
        // Cron-Expression validieren
        if (!$this->cronParser->isValid($cron)) {
            throw new QueueException("Ungültige Cron-Expression: $cron");
        }

        // Prüfen, ob die Queue existiert
        if (!$this->queue->hasQueue($queueName)) {
            throw new QueueException("Queue '$queueName' existiert nicht");
        }

        // Eindeutigen Namen setzen
        $this->recurringJobs[$name] = [
            'queue' => $queueName,
            'job' => $job,
            'cron' => $cron,
            'priority' => $priority,
            'last_run' => null
        ];

        // Job registrieren
        try {
            return $this->queue->recurring($queueName, $job, $cron, $priority);
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Registrieren des wiederkehrenden Jobs", [
                'name' => $name,
                'queue' => $queueName,
                'cron' => $cron,
                'exception' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Plant einen Job für einen bestimmten Zeitpunkt ein
     *
     * @param string $queueName Name der Queue
     * @param JobInterface $job Job-Objekt
     * @param DateTime $executeAt Ausführungszeitpunkt
     * @param int|null $priority Optionale Priorität
     * @return string Job-ID
     */
    public function scheduleAt(
        string       $queueName,
        JobInterface $job,
        DateTime     $executeAt,
        ?int         $priority = null
    ): string
    {
        try {
            return $this->queue->schedule($queueName, $job, $executeAt, $priority);
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Einplanen des Jobs", [
                'queue' => $queueName,
                'execute_at' => $executeAt->format('Y-m-d H:i:s'),
                'exception' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Plant einen Job für einen zukünftigen Zeitpunkt ein
     *
     * @param string $queueName Name der Queue
     * @param JobInterface $job Job-Objekt
     * @param int $delay Verzögerung in Sekunden
     * @param int|null $priority Optionale Priorität
     * @return string Job-ID
     */
    public function scheduleIn(
        string       $queueName,
        JobInterface $job,
        int          $delay,
        ?int         $priority = null
    ): string
    {
        $executeAt = new DateTime();
        $executeAt->modify("+$delay seconds");

        return $this->scheduleAt($queueName, $job, $executeAt, $priority);
    }

    /**
     * Überprüft und führt fällige wiederkehrende Jobs aus
     *
     * @return int Anzahl der ausgeführten Jobs
     */
    public function runDueJobs(): int
    {
        $executedCount = 0;
        $now = new DateTime();

        $this->logger->debug("Überprüfe fällige Jobs");

        foreach ($this->recurringJobs as $name => &$jobData) {
            try {
                $cron = $jobData['cron'];
                $lastRun = $jobData['last_run'];

                // Prüfen, ob der Job ausgeführt werden muss
                if ($lastRun === null || $this->cronParser->isDue($cron, $lastRun)) {
                    $queueName = $jobData['queue'];
                    $job = $jobData['job'];
                    $priority = $jobData['priority'];

                    // Job zur Queue hinzufügen
                    $jobId = $this->queue->push($queueName, $job, null, $priority);

                    // Letzte Ausführung aktualisieren
                    $jobData['last_run'] = $now;

                    $this->logger->info("Wiederkehrender Job ausgeführt", [
                        'name' => $name,
                        'queue' => $queueName,
                        'job_id' => $jobId,
                        'cron' => $cron
                    ]);

                    $executedCount++;
                }
            } catch (Throwable $e) {
                $this->logger->error("Fehler beim Ausführen des wiederkehrenden Jobs", [
                    'name' => $name,
                    'exception' => $e->getMessage()
                ]);
            }
        }

        return $executedCount;
    }

    /**
     * Entfernt einen wiederkehrenden Job
     *
     * @param string $name Name des Jobs
     * @return bool True, wenn der Job erfolgreich entfernt wurde
     */
    public function removeRecurringJob(string $name): bool
    {
        if (!isset($this->recurringJobs[$name])) {
            return false;
        }

        $jobData = $this->recurringJobs[$name];
        unset($this->recurringJobs[$name]);

        try {
            // Job aus der Queue entfernen
            // Hinweis: Diese Funktionalität hängt von der konkreten Queue-Implementierung ab
            // und ist möglicherweise nicht in allen Implementierungen verfügbar

            $this->logger->info("Wiederkehrender Job entfernt", [
                'name' => $name,
                'queue' => $jobData['queue']
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Entfernen des wiederkehrenden Jobs", [
                'name' => $name,
                'exception' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Gibt alle definierten wiederkehrenden Jobs zurück
     *
     * @return array Liste der wiederkehrenden Jobs
     */
    public function getRecurringJobs(): array
    {
        $jobs = [];

        foreach ($this->recurringJobs as $name => $jobData) {
            $jobs[$name] = [
                'queue' => $jobData['queue'],
                'job_class' => get_class($jobData['job']),
                'cron' => $jobData['cron'],
                'priority' => $jobData['priority'],
                'last_run' => $jobData['last_run'] ? $jobData['last_run']->format('Y-m-d H:i:s') : null
            ];
        }

        return $jobs;
    }
}