<?php


namespace Src\Queue;

use DateTime;
use Exception;
use Src\Log\LoggerInterface;
use Src\Queue\Exception\JobTimeoutException;
use Src\Queue\Exception\QueueException;
use Throwable;

/**
 * Worker zum Verarbeiten von Jobs aus einer Queue
 */
class Worker
{
    /** @var Queue Queue-Service */
    private Queue $queue;

    /** @var LoggerInterface Logger */
    private LoggerInterface $logger;

    /** @var array Konfiguration für den Worker */
    private array $config;

    /** @var array Event-Listener */
    private array $listeners = [];

    /** @var bool Ob der Worker gestoppt werden soll */
    private bool $shouldStop = false;

    /** @var int Anzahl der verarbeiteten Jobs */
    private int $processedJobs = 0;

    /** @var int Anzahl der fehlgeschlagenen Jobs */
    private int $failedJobs = 0;

    /** @var DateTime|null Startzeitpunkt */
    private ?DateTime $startedAt = null;

    /** @var string[] Namen der zu verarbeitenden Queues */
    private array $queues;

    /** @var bool Ob der Worker läuft */
    private bool $isRunning = false;

    /**
     * Erstellt einen neuen Worker
     *
     * @param Queue $queue Queue-Service
     * @param LoggerInterface $logger Logger
     * @param array $config Konfiguration für den Worker
     */
    public function __construct(Queue $queue, LoggerInterface $logger, array $config = [])
    {
        $this->queue = $queue;
        $this->logger = $logger;

        // Standardkonfiguration
        $defaultConfig = [
            'sleep' => 3,          // Wartezeit in Sekunden bei leerer Queue
            'maxJobs' => 0,        // Maximale Anzahl an Jobs (0 = unbegrenzt)
            'maxTime' => 0,        // Maximale Laufzeit in Sekunden (0 = unbegrenzt)
            'maxMemory' => 0,      // Maximaler Speicherverbrauch in MB (0 = unbegrenzt)
            'timeout' => 60,       // Timeout für einzelne Jobs in Sekunden
            'stopOnException' => false, // Ob der Worker bei einer Exception anhalten soll
            'pruneInterval' => 600, // Intervall für Queue-Bereinigung in Sekunden
            'memoryLimit' => 0,    // Maximaler Speicherverbrauch in Bytes (0 = unbegrenzt)
        ];

        $this->config = array_merge($defaultConfig, $config);
        $this->queues = [];
    }

    /**
     * Fügt eine zu verarbeitende Queue hinzu
     *
     * @param string $name Name der Queue
     * @return self
     */
    public function addQueue(string $name): self
    {
        if (!in_array($name, $this->queues, true)) {
            $this->queues[] = $name;
        }

        return $this;
    }

    /**
     * Setzt die zu verarbeitenden Queues
     *
     * @param array $queues Namen der Queues
     * @return self
     */
    public function setQueues(array $queues): self
    {
        $this->queues = $queues;
        return $this;
    }

    /**
     * Startet den Worker zur Verarbeitung von Jobs
     *
     * @return void
     */
    public function run(): void
    {
        if (empty($this->queues)) {
            $this->logger->warning("Keine Queues zum Verarbeiten konfiguriert");
            return;
        }

        $this->isRunning = true;
        $this->shouldStop = false;
        $this->startedAt = new DateTime();
        $this->processedJobs = 0;
        $this->failedJobs = 0;

        $this->logger->info("Worker gestartet", [
            'queues' => $this->queues,
            'config' => $this->config
        ]);

        $this->fireEvent('worker.started');

        $lastPruneTime = time();

        while (!$this->shouldStop()) {
            // Verfügbare Jobs in den Queues verarbeiten
            $processedAny = false;

            foreach ($this->queues as $queueName) {
                try {
                    $job = $this->queue->pop($queueName);

                    if ($job !== null) {
                        $this->processJob($job);
                        $processedAny = true;
                    }
                } catch (Throwable $e) {
                    $this->logger->error("Fehler beim Abrufen des Jobs aus der Queue", [
                        'queue' => $queueName,
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    $this->fireEvent('queue.error', [$e]);

                    if ($this->config['stopOnException']) {
                        $this->shouldStop = true;
                        break;
                    }
                }
            }

            // Queue-Bereinigung in regelmäßigen Abständen
            if ($this->config['pruneInterval'] > 0 && (time() - $lastPruneTime) >= $this->config['pruneInterval']) {
                $this->pruneQueues();
                $lastPruneTime = time();
            }

            // Wenn keine Jobs verarbeitet wurden, warten
            if (!$processedAny) {
                $this->sleep();
            }

            // Garbage Collection
            if ($this->processedJobs % 100 === 0) {
                $this->collectGarbage();
            }
        }

        $this->logger->info("Worker beendet", [
            'queues' => $this->queues,
            'processed_jobs' => $this->processedJobs,
            'failed_jobs' => $this->failedJobs,
            'runtime' => $this->getRuntime()
        ]);

        $this->fireEvent('worker.stopped');
        $this->isRunning = false;
    }

    /**
     * Verarbeitet einen einzelnen Job
     *
     * @param Job $job Zu verarbeitender Job
     * @return bool True, wenn der Job erfolgreich verarbeitet wurde
     */
    public function processJob(Job $job): bool
    {
        $jobId = $job->getId();
        $queueName = $job->getQueue();

        $this->logger->debug("Job wird verarbeitet", [
            'job_id' => $jobId,
            'queue' => $queueName,
            'attempts' => $job->getAttempts()
        ]);

        $this->fireEvent('job.processing', [$job]);

        $startTime = microtime(true);
        $memoryBefore = memory_get_usage();

        try {
            // Timeout überwachen
            $timeout = $this->config['timeout'];

            // Job mit Timeout ausführen
            $success = $this->runJobWithTimeout($job, $timeout);

            $endTime = microtime(true);
            $memoryAfter = memory_get_usage();
            $memoryDelta = $memoryAfter - $memoryBefore;
            $timeElapsed = round(($endTime - $startTime) * 1000, 2);

            if ($success) {
                $this->processedJobs++;

                $this->logger->info("Job erfolgreich verarbeitet", [
                    'job_id' => $jobId,
                    'queue' => $queueName,
                    'time_ms' => $timeElapsed,
                    'memory_delta' => $this->formatBytes($memoryDelta)
                ]);

                $this->fireEvent('job.processed', [$job]);
            } else {
                $this->failedJobs++;

                $this->logger->warning("Job mit Fehler verarbeitet", [
                    'job_id' => $jobId,
                    'queue' => $queueName,
                    'time_ms' => $timeElapsed,
                    'memory_delta' => $this->formatBytes($memoryDelta)
                ]);

                $this->fireEvent('job.failed', [$job]);
            }

            return $success;
        } catch (Throwable $e) {
            $this->failedJobs++;

            $this->logger->error("Fehler bei der Job-Verarbeitung", [
                'job_id' => $jobId,
                'queue' => $queueName,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Job als fehlgeschlagen markieren
            $job->markAsFailed($e);

            $this->fireEvent('job.exception', [$job, $e]);

            if ($this->config['stopOnException']) {
                $this->shouldStop = true;
            }

            return false;
        }
    }

    /**
     * Führt einen Job mit Timeout aus
     *
     * @param Job $job Zu verarbeitender Job
     * @param int $timeout Timeout in Sekunden
     * @return bool True, wenn der Job erfolgreich verarbeitet wurde
     * @throws JobTimeoutException Wenn der Job den Timeout überschreitet
     */
    private function runJobWithTimeout(Job $job, int $timeout): bool
    {
        // In PHP können wir keinen echten Timeout für Funktionen setzen
        // Daher müssen wir auf externes Timeout-Management zurückgreifen

        // Überprüfen, ob der Job bereits als reserviert markiert ist
        if (!$job->isReserved()) {
            $job->markAsReserved();
        }

        // Job verarbeiten
        return $this->queue->process($job);
    }

    /**
     * Bereinigt alte Jobs aus allen konfigurierten Queues
     *
     * @return void
     */
    private function pruneQueues(): void
    {
        foreach ($this->queues as $queueName) {
            try {
                $prunedCount = $this->queue->prune($queueName);

                if ($prunedCount > 0) {
                    $this->logger->info("Alte Jobs bereinigt", [
                        'queue' => $queueName,
                        'count' => $prunedCount
                    ]);
                }
            } catch (Throwable $e) {
                $this->logger->error("Fehler beim Bereinigen der Queue", [
                    'queue' => $queueName,
                    'exception' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Prüft, ob der Worker anhalten sollte
     *
     * @return bool True, wenn der Worker anhalten sollte
     */
    private function shouldStop(): bool
    {
        // Manuelles Stoppen
        if ($this->shouldStop) {
            return true;
        }

        // Maximale Anzahl an Jobs erreicht
        if ($this->config['maxJobs'] > 0 && $this->processedJobs >= $this->config['maxJobs']) {
            $this->logger->info("Maximale Anzahl an Jobs erreicht", [
                'processed_jobs' => $this->processedJobs,
                'max_jobs' => $this->config['maxJobs']
            ]);

            return true;
        }

        // Maximale Laufzeit erreicht
        if ($this->config['maxTime'] > 0 && $this->getRuntime() >= $this->config['maxTime']) {
            $this->logger->info("Maximale Laufzeit erreicht", [
                'runtime' => $this->getRuntime(),
                'max_time' => $this->config['maxTime']
            ]);

            return true;
        }

        // Maximaler Speicherverbrauch erreicht
        if ($this->config['maxMemory'] > 0) {
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;

            if ($memoryUsage >= $this->config['maxMemory']) {
                $this->logger->info("Maximaler Speicherverbrauch erreicht", [
                    'memory_usage' => round($memoryUsage, 2) . ' MB',
                    'max_memory' => $this->config['maxMemory'] . ' MB'
                ]);

                return true;
            }
        }

        // Speicherlimit des Systems erreicht
        if ($this->config['memoryLimit'] > 0 && memory_get_usage(true) >= $this->config['memoryLimit']) {
            $this->logger->info("Systemspeicherlimit erreicht", [
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'memory_limit' => $this->formatBytes($this->config['memoryLimit'])
            ]);

            return true;
        }

        return false;
    }

    /**
     * Gibt die Laufzeit des Workers in Sekunden zurück
     *
     * @return int Laufzeit in Sekunden
     */
    private function getRuntime(): int
    {
        if ($this->startedAt === null) {
            return 0;
        }

        $now = new DateTime();
        $diff = $now->getTimestamp() - $this->startedAt->getTimestamp();

        return $diff;
    }

    /**
     * Führt Garbage Collection durch
     *
     * @return void
     */
    private function collectGarbage(): void
    {
        $memoryBefore = memory_get_usage();

        // PHP Garbage Collection auslösen
        if (gc_enabled()) {
            gc_collect_cycles();
        }

        $memoryAfter = memory_get_usage();
        $memoryDelta = $memoryBefore - $memoryAfter;

        if ($memoryDelta > 0) {
            $this->logger->debug("Garbage Collection durchgeführt", [
                'memory_freed' => $this->formatBytes($memoryDelta)
            ]);
        }
    }

    /**
     * Wartet für eine bestimmte Zeit
     *
     * @return void
     */
    private function sleep(): void
    {
        $seconds = $this->config['sleep'];

        if ($seconds > 0) {
            $this->fireEvent('worker.sleep', [$seconds]);
            sleep($seconds);
        }
    }

    /**
     * Stoppt den Worker
     *
     * @param bool $wait Ob auf das Beenden des aktuellen Jobs gewartet werden soll
     * @return void
     */
    public function stop(bool $wait = true): void
    {
        $this->shouldStop = true;

        $this->logger->info("Worker wird gestoppt", [
            'wait' => $wait
        ]);

        if ($wait && $this->isRunning) {
            // Auf das Beenden des Workers warten
            $this->logger->info("Warte auf das Beenden des aktuellen Jobs...");

            // Einfache Implementierung: Warten bis isRunning false wird
            // In einer realen Anwendung würde man wahrscheinlich eine bessere Synchronisierungsmethode verwenden
            while ($this->isRunning) {
                usleep(100000); // 100ms warten
            }
        }
    }

    /**
     * Registriert einen Event-Listener
     *
     * @param string $event Event-Name
     * @param callable $callback Callback-Funktion
     * @return self
     */
    public function on(string $event, callable $callback): self
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }

        $this->listeners[$event][] = $callback;

        return $this;
    }

    /**
     * Löst ein Event aus
     *
     * @param string $event Event-Name
     * @param array $params Parameter für die Callback-Funktion
     * @return void
     */
    private function fireEvent(string $event, array $params = []): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $callback) {
            try {
                call_user_func_array($callback, $params);
            } catch (Throwable $e) {
                $this->logger->warning("Fehler bei Event-Listener", [
                    'event' => $event,
                    'exception' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Formatiert eine Bytezahl in lesbare Größe
     *
     * @param int $bytes Bytezahl
     * @param int $precision Nachkommastellen
     * @return string Formatierte Größe
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Gibt die Anzahl der verarbeiteten Jobs zurück
     *
     * @return int
     */
    public function getProcessedJobs(): int
    {
        return $this->processedJobs;
    }

    /**
     * Gibt die Anzahl der fehlgeschlagenen Jobs zurück
     *
     * @return int
     */
    public function getFailedJobs(): int
    {
        return $this->failedJobs;
    }

    /**
     * Gibt den Startzeitpunkt zurück
     *
     * @return DateTime|null
     */
    public function getStartedAt(): ?DateTime
    {
        return $this->startedAt;
    }

    /**
     * Gibt zurück, ob der Worker läuft
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }
}