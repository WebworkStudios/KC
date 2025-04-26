<?php

namespace Src\Log;

use RuntimeException;

/**
 * Logger, der Log-Prozessoren unterstützt
 *
 * Ermöglicht das Hinzufügen von Prozessoren, die Nachrichten und Kontext vor
 * dem eigentlichen Logging bearbeiten können.
 */
class ProcessorLogger implements LoggerInterface
{
    /** @var LoggerInterface Ziel-Logger */
    private LoggerInterface $logger;

    /** @var array<callable> Prozessoren für Nachrichten und Kontext */
    private array $processors = [];

    /**
     * Erstellt einen neuen ProcessorLogger
     *
     * @param LoggerInterface $logger Ziel-Logger
     * @param array $processors Initiale Prozessoren
     */
    public function __construct(LoggerInterface $logger, array $processors = [])
    {
        $this->logger = $logger;

        foreach ($processors as $processor) {
            $this->addProcessor($processor);
        }
    }

    /**
     * Fügt einen Prozessor hinzu
     *
     * @param callable $processor Prozessor-Funktion (Level, Nachricht, Kontext) => Kontext
     * @return self
     */
    public function addProcessor(callable $processor): self
    {
        $this->processors[] = $processor;
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
        // Prozessoren ausführen
        $processedContext = $context;

        foreach ($this->processors as $processor) {
            $processedContext = $processor($level, $message, $processedContext);

            if (!is_array($processedContext)) {
                throw new RuntimeException(
                    "Prozessor muss ein Array zurückgeben, " . gettype($processedContext) . " erhalten"
                );
            }
        }

        // An Ziel-Logger weiterleiten
        $this->logger->log($level, $message, $processedContext);
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