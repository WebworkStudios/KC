<?php

namespace Src\Log;

use InvalidArgumentException;

/**
 * Abstrakte Basis-Implementierung eines Loggers
 *
 * Bietet gemeinsame Funktionalität für alle Logger-Implementierungen
 * und implementiert die grundlegenden Log-Methoden.
 */
abstract class AbstractLogger implements LoggerInterface
{
    /** @var array Gültige Log-Levels */
    protected const LEVELS = [
        'emergency',
        'alert',
        'critical',
        'error',
        'warning',
        'notice',
        'info',
        'debug'
    ];

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
        // Prüfen ob Log-Level gültig ist
        if (!in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException(
                "Ungültiges Log-Level: $level. Erlaubte Levels sind: " . implode(', ', self::LEVELS)
            );
        }

        $this->doLog($level, $message, $context);
    }

    /**
     * Führt das eigentliche Logging aus
     *
     * Diese Methode muss von konkreten Logger-Implementierungen überschrieben werden
     *
     * @param string $level Log-Level
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht
     * @return void
     */
    abstract protected function doLog(string $level, string $message, array $context = []): void;

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

    /**
     * Ersetzt Platzhalter in der Nachricht mit Kontextwerten
     *
     * @param string $message Nachricht mit Platzhaltern
     * @param array $context Kontextwerte
     * @return string Formatierte Nachricht
     */
    protected function interpolate(string $message, array $context = []): string
    {
        // Einfache Platzhalter-Ersetzung im Format {key}
        $replace = [];
        foreach ($context as $key => $val) {
            // Nur skalare Werte direkt einsetzen
            if (is_scalar($val) || $val === null || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Konvertiert einen Kontext in einen formatierten String
     *
     * @param array $context Kontext-Array
     * @return string Formatierter Kontext
     */
    protected function formatContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }

        $result = [];

        foreach ($context as $key => $value) {
            $formatted = $this->formatValue($value);
            $result[] = "$key=$formatted";
        }

        return '[' . implode(' ', $result) . ']';
    }

    /**
     * Formatiert einen einzelnen Wert für die Log-Ausgabe
     *
     * @param mixed $value Zu formatierender Wert
     * @return string Formatierter Wert
     */
    protected function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            // Strings in Anführungszeichen setzen
            if (is_string($value)) {
                // Lange Strings abschneiden
                if (mb_strlen($value) > 100) {
                    $value = mb_substr($value, 0, 97) . '...';
                }
                return '"' . str_replace('"', '\"', $value) . '"';
            }
            return (string)$value;
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            return get_class($value);
        }

        if (is_resource($value)) {
            return 'resource(' . get_resource_type($value) . ')';
        }

        return 'unknown';
    }
}