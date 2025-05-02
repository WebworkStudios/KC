<?php

namespace Src\Queue\Connection;

use DateTime;
use Src\Queue\Job;
use Src\Queue\Job\JobInterface;
use Throwable;

/**
 * Interface für Queue-Verbindungen
 */
interface ConnectionInterface
{
    /**
     * Fügt einen Job zur Queue hinzu
     *
     * @param JobInterface $job Job-Objekt
     * @param DateTime|null $executeAt Optionaler Ausführungszeitpunkt
     * @param int $priority Priorität (höherer Wert = höhere Priorität)
     * @return string Job-ID
     */
    public function push(JobInterface $job, ?DateTime $executeAt = null, int $priority = 0): string;

    /**
     * Holt den nächsten ausführbaren Job aus der Queue
     *
     * @return Job|null Job-Objekt oder null, wenn keine Jobs verfügbar sind
     */
    public function pop(): ?Job;

    /**
     * Entfernt einen Job aus der Queue
     *
     * @param string $jobId Job-ID
     * @return bool True, wenn der Job erfolgreich entfernt wurde
     */
    public function remove(string $jobId): bool;

    /**
     * Plant einen Job für einen bestimmten Zeitpunkt ein
     *
     * @param JobInterface $job Job-Objekt
     * @param DateTime $executeAt Ausführungszeitpunkt
     * @param int $priority Priorität (höherer Wert = höhere Priorität)
     * @return string Job-ID
     */
    public function schedule(JobInterface $job, DateTime $executeAt, int $priority = 0): string;

    /**
     * Gibt zurück, ob wiederkehrende Jobs unterstützt werden
     *
     * @return bool True, wenn wiederkehrende Jobs unterstützt werden
     */
    public function supportsRecurring(): bool;

    /**
     * Registriert einen wiederkehrenden Job
     *
     * @param JobInterface $job Job-Objekt
     * @param string $cron Cron-Expression für die Wiederholung
     * @param int $priority Priorität (höherer Wert = höhere Priorität)
     * @return string Job-ID
     */
    public function registerRecurringJob(JobInterface $job, string $cron, int $priority = 0): string;

    /**
     * Gibt Statistiken über die Queue zurück
     *
     * @return array Statistik-Daten
     */
    public function getStats(): array;

    /**
     * Bereinigt alte verarbeitete Jobs aus der Queue
     *
     * @param int $maxAge Maximales Alter in Sekunden
     * @return int Anzahl der bereinigten Jobs
     */
    public function prune(int $maxAge): int;

    /**
     * Leert die Queue vollständig
     *
     * @return int Anzahl der entfernten Jobs
     */
    public function clear(): int;

    /**
     * Gibt zurück, ob die Verbindung eine Speicherung für fehlgeschlagene Jobs unterstützt
     *
     * @return bool True, wenn fehlgeschlagene Jobs gespeichert werden können
     */
    public function hasFailedJobStorage(): bool;

    /**
     * Speichert einen fehlgeschlagenen Job
     *
     * @param Job $job Fehlgeschlagener Job
     * @param Throwable $exception Aufgetretene Exception
     * @return bool True, wenn der Job erfolgreich gespeichert wurde
     */
    public function storeFailedJob(Job $job, Throwable $exception): bool;

    /**
     * Gibt fehlgeschlagene Jobs zurück
     *
     * @param int $limit Maximale Anzahl der zurückgegebenen Jobs
     * @param int $offset Offset für Pagination
     * @return array Fehlgeschlagene Jobs
     */
    public function getFailedJobs(int $limit, int $offset): array;

    /**
     * Führt einen fehlgeschlagenen Job erneut aus
     *
     * @param string $jobId ID des fehlgeschlagenen Jobs
     * @return bool True, wenn der Job erfolgreich erneut zur Queue hinzugefügt wurde
     */
    public function retryFailedJob(string $jobId): bool;

    /**
     * Schließt die Verbindung
     *
     * @return void
     */
    public function close(): void;
}