<?php


namespace Src\Log;

use Throwable;

/**
 * Hilfsklasse zum Protokollieren von Exceptions
 */
class LogException
{
    /**
     * Protokolliert eine Exception mit detaillierten Informationen
     *
     * @param LoggerInterface $logger Logger für die Ausgabe
     * @param Throwable $exception Die zu protokollierende Exception
     * @param string $level Log-Level (error, critical, alert, emergency)
     * @param string $message Optionale Nachricht vor den Exception-Details
     * @param array $context Zusätzlicher Kontext für die Logeinträge
     * @return void
     */
    public static function log(
        LoggerInterface $logger,
        Throwable       $exception,
        string          $level = 'error',
        string          $message = '',
        array           $context = []
    ): void
    {
        // Standardnachricht, falls keine angegeben
        if (empty($message)) {
            $message = 'Exception aufgetreten: ' . $exception->getMessage();
        }

        // Exception-Informationen zum Kontext hinzufügen
        $exceptionContext = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => self::formatTrace($exception->getTrace()),
        ];

        // Kontext zusammenführen, mit Priorität für bestehenden Kontext
        $fullContext = array_merge($exceptionContext, $context);

        // Exception protokollieren
        $logger->log($level, $message, $fullContext);

        // Auch vorherige Exceptions protokollieren, wenn vorhanden
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            $logger->log($level, 'Vorherige Exception: ' . $previous->getMessage(), [
                'exception' => get_class($previous),
                'code' => $previous->getCode(),
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
                'trace' => self::formatTrace($previous->getTrace()),
            ]);
        }
    }

    /**
     * Formatiert einen Stack-Trace für bessere Lesbarkeit im Log
     *
     * @param array $trace Stack-Trace-Array
     * @return array Formatierter Stack-Trace
     */
    private static function formatTrace(array $trace): array
    {
        $result = [];

        foreach ($trace as $index => $frame) {
            $functionCall = '';

            // Klasse und Methode hinzufügen, falls vorhanden
            if (isset($frame['class'])) {
                $functionCall .= $frame['class'] . $frame['type'];
            }

            // Funktion hinzufügen
            $functionCall .= $frame['function'] ?? 'unknown';

            // Argumente formatieren, aber limitieren
            $args = [];
            if (isset($frame['args'])) {
                foreach ($frame['args'] as $arg) {
                    $args[] = self::formatArgument($arg);
                }
            }

            $result[$index] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $functionCall,
                'args' => $args,
            ];
        }

        return $result;
    }

    /**
     * Formatiert ein einzelnes Argument für die Log-Ausgabe
     *
     * @param mixed $arg Zu formatierendes Argument
     * @return string Formatiertes Argument
     */
    private static function formatArgument(mixed $arg): string
    {
        if ($arg === null) {
            return 'null';
        }

        if (is_bool($arg)) {
            return $arg ? 'true' : 'false';
        }

        if (is_scalar($arg)) {
            if (is_string($arg)) {
                // Lange Strings kürzen
                if (mb_strlen($arg) > 50) {
                    return '"' . mb_substr($arg, 0, 47) . '..."';
                }
                return '"' . $arg . '"';
            }
            return (string)$arg;
        }

        if (is_array($arg)) {
            return 'array(' . count($arg) . ')';
        }

        if (is_object($arg)) {
            return get_class($arg);
        }

        if (is_resource($arg)) {
            return 'resource(' . get_resource_type($arg) . ')';
        }

        return 'unknown';
    }
}