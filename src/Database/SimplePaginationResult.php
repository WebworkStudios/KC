<?php


namespace Src\Database;

/**
 * Ergebnis einer einfach paginierten Datenbankabfrage ohne Gesamtzahl
 *
 * @template T
 */
readonly class SimplePaginationResult
{
    /**
     * Erstellt ein neues einfaches Paginierungsergebnis
     *
     * @param array<T> $data Ergebnisdaten
     * @param int $perPage Anzahl der Elemente pro Seite
     * @param int $currentPage Aktuelle Seitennummer
     * @param bool $hasMore Gibt an, ob es weitere Seiten gibt
     * @param int|null $nextPage Nächste Seitennummer oder null, wenn es keine gibt
     * @param int|null $prevPage Vorherige Seitennummer oder null, wenn es keine gibt
     */
    public function __construct(
        public array $data,
        public int   $perPage,
        public int   $currentPage,
        public bool  $hasMore,
        public ?int  $nextPage,
        public ?int  $prevPage
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
                'per_page' => $this->perPage,
                'has_more' => $this->hasMore,
                'next_page' => $this->nextPage,
                'prev_page' => $this->prevPage
            ]
        ];
    }

    /**
     * Prüft, ob es eine vorherige Seite gibt
     *
     * @return bool True, wenn es eine vorherige Seite gibt
     */
    public function hasPreviousPage(): bool
    {
        return $this->prevPage !== null;
    }

    /**
     * Prüft, ob es eine nächste Seite gibt
     *
     * @return bool True, wenn es eine nächste Seite gibt
     */
    public function hasNextPage(): bool
    {
        return $this->hasMore;
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