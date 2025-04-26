<?php


namespace Src\Log;

use DateTimeInterface;
use DateTimeImmutable;
use RuntimeException;

/**
 * Logger, der in eine Datei schreibt
 */
class FileLogger extends AbstractLogger
{
    /** @var string Minimales Log-Level, das protokolliert wird */
    private string $minLevel;

    /** @var resource|null Datei-Handle */
    private $fileHandle = null;

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
     * Erstellt einen neuen FileLogger
     *
     * @param string $logFile Pfad zur Log-Datei
     * @param string $minLevel Minimales Log-Level (emergency, alert, critical, error, warning, notice, info, debug)
     * @param string $mode Dateimodus (a = append, w = überschreiben)
     * @throws RuntimeException Wenn die Datei nicht geöffnet werden kann
     */
    public function __construct(
        private readonly string $logFile,
        string                  $minLevel = 'debug',
        string                  $mode = 'a'
    )
    {
        if (!in_array($minLevel, self::LEVELS, true)) {
            throw new \InvalidArgumentException("Ungültiges Log-Level: $minLevel");
        }

        $this->minLevel = $minLevel;

        // Verzeichnis erstellen, falls es nicht existiert
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            throw new RuntimeException("Log-Verzeichnis konnte nicht erstellt werden: $logDir");
        }

        // Datei öffnen
        $this->fileHandle = fopen($this->logFile, $mode);
        if ($this->fileHandle === false) {
            throw new RuntimeException("Log-Datei konnte nicht geöffnet werden: {$this->logFile}");
        }
    }

    /**
     * Destruktor: Schließt die Datei, wenn das Objekt zerstört wird
     */
    public function __destruct()
    {
        if ($this->fileHandle !== null) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
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

        if ($this->fileHandle === null) {
            // Versuchen, die Datei erneut zu öffnen
            $this->fileHandle = fopen($this->logFile, 'a');
            if ($this->fileHandle === false) {
                error_log("Konnte Log-Datei nicht öffnen: {$this->logFile}");
                return;
            }
        }

        $datetime = new DateTimeImmutable();
        $timestamp = $datetime->format(DateTimeInterface::RFC3339);

        // Nachricht mit Platzhaltern ersetzen
        $message = $this->interpolate($message, $context);

        // Kontext für strukturiertes Logging formatieren
        $formattedContext = $this->formatContext($context);

        // Log-Zeile formatieren
        $logLine = sprintf(
            "[%s] %s: %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $formattedContext
        );

        // In Datei schreiben
        fwrite($this->fileHandle, $logLine);
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