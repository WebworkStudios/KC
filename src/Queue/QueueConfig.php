<?php


namespace Src\Queue;

use Src\Queue\Connection\ConnectionFactoryInterface;

/**
 * Konfiguration für eine Queue
 */
class QueueConfig
{
    /** @var ConnectionFactoryInterface Factory für die Erstellung von Queue-Verbindungen */
    private ConnectionFactoryInterface $connectionFactory;

    /** @var int Maximale Anzahl an Wiederholungsversuchen für fehlgeschlagene Jobs */
    private int $maxRetries = 3;

    /** @var int Verzögerung zwischen Wiederholungsversuchen in Sekunden */
    private int $retryDelay = 60;

    /** @var RetryStrategy Strategie für Wiederholungsversuche */
    private RetryStrategy $retryStrategy = RetryStrategy::EXPONENTIAL;

    /** @var int Standardpriorität für Jobs */
    private int $defaultPriority = 0;

    /** @var int Standardverzögerung für Jobs in Sekunden */
    private int $defaultDelay = 0;

    /** @var bool Ob alte Jobs automatisch bereinigt werden sollen */
    private bool $autoPrune = true;

    /** @var int Maximales Alter für alte Jobs in Sekunden */
    private int $maxAge = 604800; // 7 Tage

    /** @var int Maximale Ausführungszeit für Jobs in Sekunden */
    private int $maxExecutionTime = 60;

    /** @var bool Ob fehlgeschlagene Jobs gespeichert werden sollen */
    private bool $storeFailedJobs = true;

    /** @var int Maximale Anzahl an Jobs pro Batch */
    private int $batchSize = 10;

    /** @var bool Ob eindeutige Jobs unterstützt werden sollen */
    private bool $supportUniqueJobs = true;

    /** @var int Zeit in Sekunden, wie lange eindeutige Jobs einzigartig bleiben */
    private int $uniqueJobsExpiration = 86400; // 24 Stunden

    /**
     * Erstellt eine neue Queue-Konfiguration
     *
     * @param ConnectionFactoryInterface $connectionFactory Factory für die Erstellung von Queue-Verbindungen
     */
    public function __construct(ConnectionFactoryInterface $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * Gibt die Connection-Factory zurück
     *
     * @return ConnectionFactoryInterface
     */
    public function getConnectionFactory(): ConnectionFactoryInterface
    {
        return $this->connectionFactory;
    }

    /**
     * Setzt die maximale Anzahl an Wiederholungsversuchen
     *
     * @param int $maxRetries Maximale Anzahl an Wiederholungsversuchen
     * @return $this
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * Gibt die maximale Anzahl an Wiederholungsversuchen zurück
     *
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Setzt die Verzögerung zwischen Wiederholungsversuchen
     *
     * @param int $retryDelay Verzögerung in Sekunden
     * @return $this
     */
    public function setRetryDelay(int $retryDelay): self
    {
        $this->retryDelay = $retryDelay;
        return $this;
    }

    /**
     * Gibt die Verzögerung zwischen Wiederholungsversuchen zurück
     *
     * @return int
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * Setzt die Strategie für Wiederholungsversuche
     *
     * @param RetryStrategy $retryStrategy Strategie für Wiederholungsversuche
     * @return $this
     */
    public function setRetryStrategy(RetryStrategy $retryStrategy): self
    {
        $this->retryStrategy = $retryStrategy;
        return $this;
    }

    /**
     * Gibt die Strategie für Wiederholungsversuche zurück
     *
     * @return RetryStrategy
     */
    public function getRetryStrategy(): RetryStrategy
    {
        return $this->retryStrategy;
    }

    /**
     * Setzt die Standardpriorität für Jobs
     *
     * @param int $defaultPriority Standardpriorität
     * @return $this
     */
    public function setDefaultPriority(int $defaultPriority): self
    {
        $this->defaultPriority = $defaultPriority;
        return $this;
    }

    /**
     * Gibt die Standardpriorität für Jobs zurück
     *
     * @return int
     */
    public function getDefaultPriority(): int
    {
        return $this->defaultPriority;
    }

    /**
     * Setzt die Standardverzögerung für Jobs
     *
     * @param int $defaultDelay Standardverzögerung in Sekunden
     * @return $this
     */
    public function setDefaultDelay(int $defaultDelay): self
    {
        $this->defaultDelay = $defaultDelay;
        return $this;
    }

    /**
     * Gibt die Standardverzögerung für Jobs zurück
     *
     * @return int
     */
    public function getDefaultDelay(): int
    {
        return $this->defaultDelay;
    }

    /**
     * Aktiviert oder deaktiviert die automatische Bereinigung
     *
     * @param bool $autoPrune Ob alte Jobs automatisch bereinigt werden sollen
     * @return $this
     */
    public function setAutoPrune(bool $autoPrune): self
    {
        $this->autoPrune = $autoPrune;
        return $this;
    }

    /**
     * Gibt zurück, ob alte Jobs automatisch bereinigt werden sollen
     *
     * @return bool
     */
    public function isAutoPrune(): bool
    {
        return $this->autoPrune;
    }

    /**
     * Setzt das maximale Alter für alte Jobs
     *
     * @param int $maxAge Maximales Alter in Sekunden
     * @return $this
     */
    public function setMaxAge(int $maxAge): self
    {
        $this->maxAge = $maxAge;
        return $this;
    }

    /**
     * Gibt das maximale Alter für alte Jobs zurück
     *
     * @return int
     */
    public function getMaxAge(): int
    {
        return $this->maxAge;
    }

    /**
     * Setzt die maximale Ausführungszeit für Jobs
     *
     * @param int $maxExecutionTime Maximale Ausführungszeit in Sekunden
     * @return $this
     */
    public function setMaxExecutionTime(int $maxExecutionTime): self
    {
        $this->maxExecutionTime = $maxExecutionTime;
        return $this;
    }

    /**
     * Gibt die maximale Ausführungszeit für Jobs zurück
     *
     * @return int
     */
    public function getMaxExecutionTime(): int
    {
        return $this->maxExecutionTime;
    }

    /**
     * Aktiviert oder deaktiviert die Speicherung fehlgeschlagener Jobs
     *
     * @param bool $storeFailedJobs Ob fehlgeschlagene Jobs gespeichert werden sollen
     * @return $this
     */
    public function setStoreFailedJobs(bool $storeFailedJobs): self
    {
        $this->storeFailedJobs = $storeFailedJobs;
        return $this;
    }

    /**
     * Gibt zurück, ob fehlgeschlagene Jobs gespeichert werden sollen
     *
     * @return bool
     */
    public function isStoreFailedJobs(): bool
    {
        return $this->storeFailedJobs;
    }

    /**
     * Setzt die maximale Anzahl an Jobs pro Batch
     *
     * @param int $batchSize Maximale Anzahl an Jobs pro Batch
     * @return $this
     */
    public function setBatchSize(int $batchSize): self
    {
        $this->batchSize = $batchSize;
        return $this;
    }

    /**
     * Gibt die maximale Anzahl an Jobs pro Batch zurück
     *
     * @return int
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Aktiviert oder deaktiviert die Unterstützung für eindeutige Jobs
     *
     * @param bool $supportUniqueJobs Ob eindeutige Jobs unterstützt werden sollen
     * @return $this
     */
    public function setSupportUniqueJobs(bool $supportUniqueJobs): self
    {
        $this->supportUniqueJobs = $supportUniqueJobs;
        return $this;
    }

    /**
     * Gibt zurück, ob eindeutige Jobs unterstützt werden sollen
     *
     * @return bool
     */
    public function isSupportUniqueJobs(): bool
    {
        return $this->supportUniqueJobs;
    }

    /**
     * Setzt die Zeit, wie lange eindeutige Jobs einzigartig bleiben
     *
     * @param int $uniqueJobsExpiration Zeit in Sekunden
     * @return $this
     */
    public function setUniqueJobsExpiration(int $uniqueJobsExpiration): self
    {
        $this->uniqueJobsExpiration = $uniqueJobsExpiration;
        return $this;
    }

    /**
     * Gibt die Zeit zurück, wie lange eindeutige Jobs einzigartig bleiben
     *
     * @return int
     */
    public function getUniqueJobsExpiration(): int
    {
        return $this->uniqueJobsExpiration;
    }
}