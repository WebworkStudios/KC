<?php

namespace Src\Log;

use Throwable;

/**
 * Logger, der Logeinträge an mehrere Logger weiterleitet
 *
 * Ermöglicht das Protokollieren in mehrere Ziele gleichzeitig
 */
class AggregateLogger implements LoggerInterface
{
    /** @var array<LoggerInterface> Array von Logger-Instanzen */
    private array $loggers = [];

    /**
     * Erstellt einen neuen AggregateLogger
     *
     * @param array<LoggerInterface> $loggers Initiale Logger-Instanzen
     */
    public function __construct(array $loggers = [])
    {
        foreach ($loggers as $logger) {
            $this->addLogger($logger);
        }
    }

    /**
     * Fügt einen Logger hinzu
     *
     * @param LoggerInterface $logger Hinzuzufügender Logger
     * @return self
     */
    public function addLogger(LoggerInterface $logger): self
    {
        $this->loggers[] = $logger;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function log(string $level, string $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (Throwable $e) {
                // Fehler im Logger nicht nach oben propagieren
                error_log('Fehler beim Logging: ' . $e->getMessage());
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}