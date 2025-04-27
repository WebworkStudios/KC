<?php


namespace Src\Database;

/**
 * Ergebnis einer cursor-paginierten Datenbankabfrage
 *
 * @template T
 */
readonly class CursorPaginationResult
{
    /**
     * Erstellt ein neues Cursor-Paginierungsergebnis
     *
     * @param array<T> $data Ergebnisdaten
     * @param int $perPage Anzahl der Elemente pro Seite
     * @param string|int|null $cursor Aktueller Cursor-Wert
     * @param string|int|null $nextCursor Nächster Cursor-Wert oder null, wenn es keinen gibt
     * @param string|int|null $prevCursor Vorheriger Cursor-Wert oder null, wenn es keinen gibt
     * @param bool $hasMore Gibt an, ob es weitere Seiten gibt
     */
    public function __construct(
        public array           $data,
        public int             $perPage,
        public string|int|null $cursor,
        public string|int|null $nextCursor,
        public string|int|null $prevCursor,
        public bool            $hasMore
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
                'cursor' => $this->cursor,
                'next_cursor' => $this->nextCursor,
                'prev_cursor' => $this->prevCursor,
                'per_page' => $this->perPage,
                'has_more' => $this->hasMore
            ]
        ];
    }

    /**
     * Gibt die URL-Parameter für die nächste Seite zurück
     *
     * @return array|null URL-Parameter als assoziatives Array oder null, wenn es keine nächste Seite gibt
     */
    public function nextPageParams(): ?array
    {
        if (!$this->hasNextPage()) {
            return null;
        }

        return [
            'cursor' => $this->nextCursor,
            'per_page' => $this->perPage
        ];
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
     * Gibt die URL-Parameter für die vorherige Seite zurück
     *
     * @return array|null URL-Parameter als assoziatives Array oder null, wenn es keine vorherige Seite gibt
     */
    public function previousPageParams(): ?array
    {
        if (!$this->hasPreviousPage()) {
            return null;
        }

        return [
            'cursor' => $this->prevCursor,
            'per_page' => $this->perPage
        ];
    }

    /**
     * Prüft, ob es eine vorherige Seite gibt
     *
     * @return bool True, wenn es eine vorherige Seite gibt
     */
    public function hasPreviousPage(): bool
    {
        return $this->prevCursor !== null;
    }
}