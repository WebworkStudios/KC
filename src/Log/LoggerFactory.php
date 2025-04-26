<?php

namespace Src\Log;

use InvalidArgumentException;
use RuntimeException;

/**
 * Factory zum Erstellen von Logger-Instanzen
 *
 * Ermöglicht es, Logger-Instanzen basierend auf Konfiguration zu erstellen
 */
class LoggerFactory
{
    /** @var array<string, string> Mapping von Logger-Typen zu Klassen */
    private array $loggerTypes = [
        'file' => FileLogger::class,
        'syslog' => SyslogLogger::class,
        'null' => NullLogger::class,
    ];

    /** @var string Verzeichnis für Log-Dateien */
    private string $logDir;

    /** @var string Standard-Log-Level */
    private string $defaultLevel;

    /**
     * Erstellt eine neue LoggerFactory
     *
     * @param string $logDir Verzeichnis für Log-Dateien
     * @param string $defaultLevel Standard-Log-Level
     */
    public function __construct(
        string $logDir = '',
        string $defaultLevel = 'debug'
    )
    {
        // Standard-Log-Verzeichnis setzen
        if (empty($logDir)) {
            if (defined('BASE_PATH')) {
                $logDir = BASE_PATH . '/logs';
            } else {
                $logDir = dirname(__DIR__, 2) . '/logs';
            }
        }

        $this->logDir = rtrim($logDir, '/\\');
        $this->defaultLevel = $defaultLevel;

        // Verzeichnis erstellen, falls es nicht existiert
        if (!is_dir($this->logDir) && !mkdir($this->logDir, 0755, true) && !is_dir($this->logDir)) {
            throw new RuntimeException("Log-Verzeichnis konnte nicht erstellt werden: {$this->logDir}");
        }
    }

    /**
     * Erstellt einen Logger basierend auf dem angegebenen Typ
     *
     * @param string $type Logger-Typ ('file', 'console', 'syslog', 'null')
     * @param array $config Konfiguration für den Logger
     * @return LoggerInterface Logger-Instanz
     * @throws InvalidArgumentException Wenn der Logger-Typ ungültig ist
     */
    public function createLogger(string $type, array $config = []): LoggerInterface
    {
        if (!isset($this->loggerTypes[$type])) {
            throw new InvalidArgumentException(
                "Ungültiger Logger-Typ: $type. Erlaubte Typen: " . implode(', ', array_keys($this->loggerTypes))
            );
        }

        $loggerClass = $this->loggerTypes[$type];

        return match ($type) {
            'file' => $this->createFileLogger($config),
            'syslog' => $this->createSyslogLogger($config),
            'null' => new NullLogger(),
            default => throw new InvalidArgumentException("Ungültiger Logger-Typ: $type"),
        };
    }

    /**
     * Erstellt einen FileLogger
     *
     * @param array $config Konfiguration für den Logger
     * @return FileLogger Logger-Instanz
     */
    private function createFileLogger(array $config = []): FileLogger
    {
        $filename = $config['filename'] ?? 'app.log';
        $level = $config['level'] ?? $this->defaultLevel;
        $mode = $config['mode'] ?? 'a';

        // Vollständigen Pfad zur Log-Datei erstellen
        $logFile = $this->logDir . '/' . $filename;

        return new FileLogger($logFile, $level, $mode);
    }

    /**
     * Erstellt einen SyslogLogger
     *
     * @param array $config Konfiguration für den Logger
     * @return SyslogLogger Logger-Instanz
     */
    private function createSyslogLogger(array $config = []): SyslogLogger
    {
        $ident = $config['ident'] ?? 'php';
        $facility = $config['facility'] ?? LOG_USER;
        $level = $config['level'] ?? $this->defaultLevel;

        return new SyslogLogger($ident, $facility, $level);
    }

    /**
     * Erstellt einen Standard-Logger basierend auf der Umgebung
     *
     * @param string $environment Umgebung ('development', 'production', etc.)
     * @param array $config Konfiguration für den Logger
     * @return LoggerInterface Logger-Instanz
     */
    public function createDefaultLogger(string $environment = 'development', array $config = []): LoggerInterface
    {
        // In Entwicklungsumgebung: Console + File
        // In Produktionsumgebung: File + Syslog
        $aggregate = new AggregateLogger();

        if ($environment === 'development') {
            $aggregate->addLogger($this->createFileLogger(array_merge($config, [
                'filename' => 'app.log',
            ])));
        } else {
            $aggregate->addLogger($this->createFileLogger(array_merge($config, [
                'filename' => 'app.log',
            ])));
            $aggregate->addLogger($this->createSyslogLogger($config));
        }

        return $aggregate;
    }

    /**
     * Registriert einen benutzerdefinierten Logger-Typ
     *
     * @param string $type Logger-Typ
     * @param string $class Logger-Klasse
     * @return self
     */
    public function registerLoggerType(string $type, string $class): self
    {
        if (!class_exists($class) || !is_subclass_of($class, LoggerInterface::class)) {
            throw new InvalidArgumentException("Klasse $class muss LoggerInterface implementieren");
        }

        $this->loggerTypes[$type] = $class;

        return $this;
    }
}