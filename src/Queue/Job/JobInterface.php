<?php


namespace Src\Queue\Job;

/**
 * Interface für ausführbare Jobs
 */
interface JobInterface
{
    /**
     * Führt den Job aus
     *
     * @return mixed Ergebnis der Job-Ausführung
     */
    public function handle(): mixed;

    /**
     * Gibt eine eindeutige ID für den Job zurück
     *
     * @return string Job-ID
     */
    public function getId(): string;

    /**
     * Gibt den Namen des Jobs zurück
     *
     * @return string Job-Name
     */
    public function getName(): string;

    /**
     * Konvertiert den Job in ein serialisierbares Format
     *
     * @return array Job-Daten
     */
    public function toArray(): array;

    /**
     * Erstellt einen Job aus einem serialisierten Format
     *
     * @param array $data Job-Daten
     * @return static Job-Instanz
     */
    public static function fromArray(array $data): static;

    /**
     * Wird aufgerufen, wenn der Job fehlschlägt
     *
     * @param \Throwable $exception Die aufgetretene Exception
     * @return void
     */
    public function failed(\Throwable $exception): void;

    /**
     * Gibt eine maximale Ausführungszeit für den Job zurück (in Sekunden)
     * Null bedeutet unbegrenzte Zeit
     *
     * @return int|null Maximale Ausführungszeit in Sekunden
     */
    public function getTimeout(): ?int;

    /**
     * Gibt zurück, ob der Job einzigartig sein soll
     * Wenn true, wird der Job nur einmal zur Queue hinzugefügt, auch wenn er mehrfach gepusht wird
     *
     * @return bool True, wenn der Job einzigartig sein soll
     */
    public function isUnique(): bool;

    /**
     * Gibt einen Eindeutigkeitsschlüssel für den Job zurück
     * Wird nur verwendet, wenn isUnique() true zurückgibt
     *
     * @return string Eindeutigkeitsschlüssel
     */
    public function getUniqueKey(): string;
}