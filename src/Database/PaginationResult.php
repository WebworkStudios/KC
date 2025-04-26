<?php


namespace Src\Database;

/**
 * Ergebnis einer paginierten Datenbankabfrage mit Metadaten
 *
 * @template T
 */
readonly class PaginationResult
{
    /**
     * Erstellt ein neues Paginierungsergebnis
     *
     * @param array<T> $data Ergebnisdaten
     * @param int $total Gesamtzahl der Elemente
     * @param int $perPage Anzahl der Elemente pro Seite
     * @param int $currentPage Aktuelle Seitennummer
     * @param int $lastPage Letzte Seitennummer
     * @param int|null $nextPage Nächste Seitennummer oder null, wenn es keine gibt
     * @param int|null $prevPage Vorherige Seitennummer oder null, wenn es keine gibt
     * @param int $from Index des ersten Elements auf der Seite
     * @param int $to Index des letzten Elements auf der Seite
     */
    public function __construct(
        public array $data,
        public int   $total,
        public int   $perPage,
        public int   $currentPage,
        public int   $lastPage,
        public ?int  $nextPage,
        public ?int  $prevPage,
        public int   $from,
        public int   $to
    )
    {
    }

    /**
     * Konvertiert das Ergebnis in ein Array
     *
     * @return array Repräsentation des Ergebnisses als Array
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'meta' => [
                'current_page' => $this->currentPage,
                'last_page' => $this->lastPage,
                'from' => $this->from,
                'to' => $this->to,
                'total' => $this->total,
                'per_page' => $this->perPage,
                'next_page' => $this->nextPage,
                'prev_page' => $this->prevPage,
                'has_more_pages' => $this->hasMorePages()
            ]
        ];
    }

    /**
     * Prüft, ob es weitere Seiten gibt
     *
     * @return bool True, wenn es weitere Seiten gibt
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Prüft, ob die aktuelle Seite die erste Seite ist
     *
     * @return bool True, wenn die aktuelle Seite die erste Seite ist
     */
    public function isFirstPage(): bool
    {
        return $this->currentPage === 1;
    }

    /**
     * Prüft, ob die aktuelle Seite die letzte Seite ist
     *
     * @return bool True, wenn die aktuelle Seite die letzte Seite ist
     */
    public function isLastPage(): bool
    {
        return $this->currentPage === $this->lastPage;
    }

    /**
     * Gibt den Seitenbereich in menschenlesbarer Form zurück
     *
     * @return string Seitenbereich (z.B. "1-10 von 100")
     */
    public function pageRange(): string
    {
        return sprintf("%d-%d von %d", $this->from, $this->to, $this->total);
    }

    /**
     * Gibt die Anzahl der Seiten zurück
     *
     * @return int Anzahl der Seiten
     */
    public function pageCount(): int
    {
        return $this->lastPage;
    }

    /**
     * Gibt die URL-Parameter für die Paginierung zurück
     *
     * @return array URL-Parameter als assoziatives Array
     */
    public function urlParams(): array
    {
        return [
            'page' => $this->currentPage,
            'per_page' => $this->perPage
        ];
    }
}