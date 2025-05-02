<?php


namespace Src\Queue;

use DateTime;
use Throwable;
use Src\Queue\Job\JobInterface;

/**
 * Repräsentiert einen Job in der Queue
 */
class Job
{
    /** @var string Job-ID */
    private string $id;

    /** @var string Queue-Name */
    private string $queue;

    /** @var JobInterface|null Job-Objekt */
    private ?JobInterface $job;

    /** @var array Job-Daten für Serialisierung */
    private array $payload;

    /** @var int Anzahl der Versuche */
    private int $attempts = 0;

    /** @var DateTime|null Zeitpunkt der letzten Ausführung */
    private ?DateTime $lastExecutedAt = null;

    /** @var DateTime|null Zeitpunkt der Reservierung */
    private ?DateTime $reservedAt = null;

    /** @var DateTime|null Zeitpunkt des Fehlschlags */
    private ?DateTime $failedAt = null;

    /** @var string|null Fehlermeldung */
    private ?string $errorMessage = null;

    /** @var string|null Fehler-Stacktrace */
    private ?string $errorTrace = null;

    /** @var DateTime|null Zeitpunkt der Erstellung */
    private ?DateTime $createdAt = null;

    /** @var DateTime|null Zeitpunkt der geplanten Ausführung */
    private ?DateTime $executeAt = null;

    /** @var int Priorität des Jobs */
    private int $priority = 0;

    /**
     * Erstellt einen neuen Job
     *
     * @param string $id Job-ID
     * @param string $queue Queue-Name
     * @param JobInterface|array $job Job-Objekt oder serialisierte Job-Daten
     * @param int $attempts Anzahl der Versuche
     * @param DateTime|null $createdAt Zeitpunkt der Erstellung
     * @param DateTime|null $executeAt Zeitpunkt der geplanten Ausführung
     * @param int $priority Priorität des Jobs
     */
    public function __construct(
        string             $id,
        string             $queue,
        JobInterface|array $job,
        int                $attempts = 0,
        ?DateTime          $createdAt = null,
        ?DateTime          $executeAt = null,
        int                $priority = 0
    )
    {
        $this->id = $id;
        $this->queue = $queue;
        $this->attempts = $attempts;
        $this->createdAt = $createdAt ?? new DateTime();
        $this->executeAt = $executeAt;
        $this->priority = $priority;

        if ($job instanceof JobInterface) {
            $this->job = $job;
            $this->payload = $job->toArray();
        } else {
            $this->job = null;
            $this->payload = $job;
        }
    }

    /**
     * Gibt die Job-ID zurück
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Gibt den Queue-Namen zurück
     *
     * @return string
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Gibt das Job-Objekt zurück
     *
     * Lädt das Job-Objekt aus dem Payload, falls noch nicht geschehen
     *
     * @return JobInterface|null
     */
    public function getJob(): ?JobInterface
    {
        if ($this->job === null && isset($this->payload['class'])) {
            try {
                $className = $this->payload['class'];

                if (class_exists($className) && is_subclass_of($className, JobInterface::class)) {
                    $this->job = $className::fromArray($this->payload);
                }
            } catch (Throwable $e) {
                // Fehler beim Laden des Jobs ignorieren
            }
        }

        return $this->job;
    }

    /**
     * Gibt die Job-Daten zurück
     *
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Gibt die Anzahl der Versuche zurück
     *
     * @return int
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Gibt den Zeitpunkt der letzten Ausführung zurück
     *
     * @return DateTime|null
     */
    public function getLastExecutedAt(): ?DateTime
    {
        return $this->lastExecutedAt;
    }

    /**
     * Gibt den Zeitpunkt der Reservierung zurück
     *
     * @return DateTime|null
     */
    public function getReservedAt(): ?DateTime
    {
        return $this->reservedAt;
    }

    /**
     * Gibt den Zeitpunkt des Fehlschlags zurück
     *
     * @return DateTime|null
     */
    public function getFailedAt(): ?DateTime
    {
        return $this->failedAt;
    }

    /**
     * Gibt die Fehlermeldung zurück
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Gibt den Fehler-Stacktrace zurück
     *
     * @return string|null
     */
    public function getErrorTrace(): ?string
    {
        return $this->errorTrace;
    }

    /**
     * Gibt den Zeitpunkt der Erstellung zurück
     *
     * @return DateTime|null
     */
    public function getCreatedAt(): ?DateTime
    {
        return $this->createdAt;
    }

    /**
     * Gibt den Zeitpunkt der geplanten Ausführung zurück
     *
     * @return DateTime|null
     */
    public function getExecuteAt(): ?DateTime
    {
        return $this->executeAt;
    }

    /**
     * Gibt die Priorität des Jobs zurück
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Markiert den Job als reserviert
     *
     * @return $this
     */
    public function markAsReserved(): self
    {
        $this->reservedAt = new DateTime();
        $this->attempts++;
        return $this;
    }

    /**
     * Markiert den Job als verarbeitet
     *
     * @return $this
     */
    public function markAsComplete(): self
    {
        $this->lastExecutedAt = new DateTime();
        $this->reservedAt = null;
        $this->failedAt = null;
        $this->errorMessage = null;
        $this->errorTrace = null;
        return $this;
    }

    /**
     * Markiert den Job für erneute Ausführung
     *
     * @param int $delay Verzögerung in Sekunden
     * @return $this
     */
    public function markForRetry(int $delay = 0): self
    {
        $this->lastExecutedAt = new DateTime();
        $this->reservedAt = null;
        $this->failedAt = null;
        $this->errorMessage = null;
        $this->errorTrace = null;

        if ($delay > 0) {
            $this->executeAt = (new DateTime())->modify("+$delay seconds");
        } else {
            $this->executeAt = null;
        }

        return $this;
    }

    /**
     * Markiert den Job als fehlgeschlagen
     *
     * @param Throwable $exception Die aufgetretene Exception
     * @return $this
     */
    public function markAsFailed(Throwable $exception): self
    {
        $this->lastExecutedAt = new DateTime();
        $this->failedAt = new DateTime();
        $this->reservedAt = null;
        $this->errorMessage = $exception->getMessage();
        $this->errorTrace = $exception->getTraceAsString();

        // failed-Methode des Jobs aufrufen, falls vorhanden
        $jobObject = $this->getJob();
        if ($jobObject !== null) {
            try {
                $jobObject->failed($exception);
            } catch (Throwable) {
                // Fehler ignorieren
            }
        }

        return $this;
    }

    /**
     * Konvertiert den Job in ein Array für die Serialisierung
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'last_executed_at' => $this->lastExecutedAt?->format('Y-m-d H:i:s'),
            'reserved_at' => $this->reservedAt?->format('Y-m-d H:i:s'),
            'failed_at' => $this->failedAt?->format('Y-m-d H:i:s'),
            'error_message' => $this->errorMessage,
            'error_trace' => $this->errorTrace,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'execute_at' => $this->executeAt?->format('Y-m-d H:i:s'),
            'priority' => $this->priority
        ];
    }

    /**
     * Erstellt einen Job aus einem Array
     *
     * @param array $data Job-Daten
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $job = new static(
            $data['id'],
            $data['queue'],
            $data['payload'],
            $data['attempts'] ?? 0,
            isset($data['created_at']) ? new DateTime($data['created_at']) : null,
            isset($data['execute_at']) ? new DateTime($data['execute_at']) : null,
            $data['priority'] ?? 0
        );

        if (isset($data['last_executed_at'])) {
            $job->lastExecutedAt = new DateTime($data['last_executed_at']);
        }

        if (isset($data['reserved_at'])) {
            $job->reservedAt = new DateTime($data['reserved_at']);
        }

        if (isset($data['failed_at'])) {
            $job->failedAt = new DateTime($data['failed_at']);
        }

        $job->errorMessage = $data['error_message'] ?? null;
        $job->errorTrace = $data['error_trace'] ?? null;

        return $job;
    }

    /**
     * Prüft, ob der Job ausführbar ist
     *
     * Ein Job ist ausführbar, wenn er nicht reserviert ist und
     * entweder keinen execute_at-Zeitpunkt hat oder dieser in der Vergangenheit liegt
     *
     * @return bool
     */
    public function isExecutable(): bool
    {
        return $this->reservedAt === null
            && ($this->executeAt === null || $this->executeAt <= new DateTime());
    }

    /**
     * Prüft, ob der Job fehlgeschlagen ist
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->failedAt !== null;
    }

    /**
     * Prüft, ob der Job reserviert ist
     *
     * @return bool
     */
    public function isReserved(): bool
    {
        return $this->reservedAt !== null;
    }

    /**
     * Prüft, ob der Job ein Timeout hat
     *
     * @param int $maxExecutionTime Maximale Ausführungszeit in Sekunden
     * @return bool
     */
    public function hasTimedOut(int $maxExecutionTime): bool
    {
        if ($this->reservedAt === null) {
            return false;
        }

        // Job-spezifisches Timeout prüfen
        $jobObject = $this->getJob();
        if ($jobObject !== null && $jobObject->getTimeout() !== null) {
            $maxExecutionTime = min($maxExecutionTime, $jobObject->getTimeout());
        }

        $now = new DateTime();
        $timeout = (clone $this->reservedAt)->modify("+$maxExecutionTime seconds");

        return $now > $timeout;
    }
}