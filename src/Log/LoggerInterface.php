<?php

namespace Src\Log;

/**
 * Interface für Logger
 *
 * Definiert die grundlegenden Methoden, die ein Logger implementieren muss,
 * basierend auf den PSR-3 Log Levels.
 */
interface LoggerInterface
{
    /**
     * Systemfehler, der sofortiges Handeln erfordert
     *
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht (z.B. ['user_id' => 123])
     * @return void
     */
    public function emergency(string $message, array $context = []): void;

    /**
     * Aktionen, die sofort ausgeführt werden müssen
     *
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht
     * @return void
     */
    public function alert(string $message, array $context = []): void;

    /**
     * Kritische Bedingungen
     *
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht
     * @return void
     */
    public function critical(string $message, array $context = []): void;

    /**
     * Laufzeitfehler, die kein sofortiges Handeln erfordern
     *
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht
     * @return void
     */
    public function error(string $message, array $context = []): void;

    /**
     * Ausnahmesituationen, die nicht notwendigerweise Fehler sind
     *
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht
     * @return void
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Normale, aber wichtige Ereignisse
     *
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht
     * @return void
     */
    public function notice(string $message, array $context = []): void;

    /**
     * Interessante Ereignisse
     *
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht
     * @return void
     */
    public function info(string $message, array $context = []): void;

    /**
     * Detaillierte Debug-Informationen
     *
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht
     * @return void
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Loggt mit beliebigem Level
     *
     * @param string $level Log-Level (emergency, alert, critical, error, warning, notice, info, debug)
     * @param string $message Log-Nachricht
     * @param array $context Kontextdaten für die Nachricht
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void;
}