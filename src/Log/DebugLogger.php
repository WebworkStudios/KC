<?php

namespace Src\Log;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Logger speziell für Debug-Zwecke mit Details über die Aufrufstelle
 */
class DebugLogger extends AbstractLogger
{
    /** @var string Minimales Log-Level, das protokolliert wird */
    private string $minLevel;

    /** @var array<LoggerInterface> Ziel-Logger für Debug-Ausgaben */
    private array $targetLoggers;

    /** @var array Level-Prioritäten (niedrigere Zahl = höhere Priorität) */
    private const LEVEL_PRIORITIES = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7
    ];

    /**
     * Erstellt einen neuen DebugLogger
     *
     * @param LoggerInterface|array<LoggerInterface> $targetLoggers Ziel-Logger für Debug-Ausgaben
     * @param string $minLevel Minimales Log-Level (emergency, alert, critical, error, warning, notice, info, debug)
     */
    public function __construct(
        LoggerInterface|array $targetLoggers = [],
        string $minLevel = 'debug'
    ) {
        if (!in_array($minLevel, self::LEVELS, true)) {
            throw new \InvalidArgumentException("Ungültiges Log-Level: $minLevel");
        }

        $this->minLevel = $minLevel;

        // Einzelnen Logger in Array konvertieren
        if ($targetLoggers instanceof LoggerInterface) {
            $targetLoggers = [$targetLoggers];
        }

        $this->targetLoggers = $targetLoggers;
    }

    /**
     * {@inheritDoc}
     */
    protected function doLog(string $level, string $message, array $context = []): void
    {
        // Prüfen, ob das Level protokolliert werden soll
        if (!$this->shouldLog($level)) {
            return;
        }

        $datetime = new DateTimeImmutable();
        $timestamp = $datetime->format(DateTimeInterface::RFC3339);

        // Aufrufstelle ermitteln
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[2] ?? $trace[0];

        $callerInfo = [
            'file' => $caller['file'] ?? 'unknown',
            'line' => $caller['line'] ?? 0,
            'function' => $caller['function'] ?? 'unknown',
            'class' => $caller['class'] ?? 'unknown',
        ];

        // Aufrufstelle zur Nachricht hinzufügen
        $callerFile = basename($callerInfo['file']);
        $callerLine = $callerInfo['line'];
        $callerClass = basename(str_replace('\\', '/', $callerInfo['class']));
        $callerFunction = $callerInfo['function'];

        $debugMessage = sprintf(
            '[%s] %s::%s (in %s:%d)',
            $timestamp,
            $callerClass,
            $callerFunction,
            $callerFile,
            $callerLine
        );

        // Kontext mit Debug-Informationen erweitern
        $extendedContext = array_merge($context, [
            'debug_file' => $callerInfo['file'],
            'debug_line' => $callerInfo['line'],
            'debug_function' => $callerInfo['function'],
            'debug_class' => $callerInfo['class'],
        ]);

        // Nachricht an Ziel-Logger weiterleiten
        foreach ($this->targetLoggers as $logger) {
            // Originalnachricht mit Debug-Informationen ergänzen
            $fullMessage = "$debugMessage: $message";
            $logger->log($level, $fullMessage, $extendedContext);
        }
    }

    /**
     * Prüft, ob ein bestimmter Log-Level protokolliert werden soll
     *
     * @param string $level Zu prüfender Log-Level
     * @return bool True, wenn der Level protokolliert werden soll
     */
    private function shouldLog(string $level): bool
    {
        $currentLevelPriority = self::LEVEL_PRIORITIES[$level] ?? PHP_INT_MAX;
        $minLevelPriority = self::LEVEL_PRIORITIES[$this->minLevel] ?? 0;

        return $currentLevelPriority <= $minLevelPriority;
    }
}