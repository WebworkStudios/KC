<?php

namespace Src\Log;

/**
 * Logger, der in das Systemlog schreibt (syslog)
 */
class SyslogLogger extends AbstractLogger
{
    /** @var string Minimales Log-Level, das protokolliert wird */
    private string $minLevel;

    /** @var bool Flag, ob Logger bereits geöffnet ist */
    private bool $opened = false;

    /** @var array Mapping von Log-Levels auf Syslog-Prioritäten */
    private const LEVEL_PRIORITY_MAP = [
        'emergency' => LOG_EMERG,
        'alert'     => LOG_ALERT,
        'critical'  => LOG_CRIT,
        'error'     => LOG_ERR,
        'warning'   => LOG_WARNING,
        'notice'    => LOG_NOTICE,
        'info'      => LOG_INFO,
        'debug'     => LOG_DEBUG
    ];

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
     * Erstellt einen neuen SyslogLogger
     *
     * @param string $ident Identifikation für Syslog-Einträge
     * @param int $facility Syslog-Facility (z.B. LOG_USER, LOG_LOCAL0, etc.)
     * @param string $minLevel Minimales Log-Level (emergency, alert, critical, error, warning, notice, info, debug)
     */
    public function __construct(
        private readonly string $ident = 'php',
        private readonly int $facility = LOG_USER,
        string $minLevel = 'debug'
    ) {
        if (!in_array($minLevel, self::LEVELS, true)) {
            throw new \InvalidArgumentException("Ungültiges Log-Level: $minLevel");
        }

        $this->minLevel = $minLevel;
    }

    /**
     * Destruktor: Schließt die Syslog-Verbindung, wenn das Objekt zerstört wird
     */
    public function __destruct()
    {
        if ($this->opened) {
            closelog();
            $this->opened = false;
        }
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

        // Syslog öffnen, falls noch nicht geschehen
        if (!$this->opened) {
            openlog($this->ident, LOG_PID, $this->facility);
            $this->opened = true;
        }

        // Syslog-Priorität ermitteln
        $priority = self::LEVEL_PRIORITY_MAP[$level] ?? LOG_NOTICE;

        // Nachricht mit Platzhaltern ersetzen
        $message = $this->interpolate($message, $context);

        // Kontext für strukturiertes Logging formatieren
        $formattedContext = $this->formatContext($context);

        // Log-Zeile formatieren und in Syslog schreiben
        $logMessage = $message . ($formattedContext ? ' ' . $formattedContext : '');
        syslog($priority, $logMessage);
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