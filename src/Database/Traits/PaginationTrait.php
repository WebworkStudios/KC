<?php

namespace Src\Database\Traits;

use InvalidArgumentException;

/**
 * Trait für Paginierungsfunktionen im QueryBuilder
 */
trait PaginationTrait
{
    /**
     * Paginiert die Ergebnisse einer Abfrage
     *
     * @param int $page Seitennummer (1-basiert)
     * @param int $perPage Anzahl der Elemente pro Seite
     * @return PaginationResult Paginierungsergebnis mit Metadaten
     */
    public function paginate(int $page = 1, int $perPage = 15): PaginationResult
    {
        if ($page < 1) {
            throw new InvalidArgumentException("Seitennummer muss positiv sein");
        }

        if ($perPage < 1) {
            throw new InvalidArgumentException("Elemente pro Seite muss positiv sein");
        }

        // Gesamtzahl der Ergebnisse ermitteln
        $total = $this->count();

        // LIMIT und OFFSET für Paginierung setzen
        $this->limit($perPage)->offset(($page - 1) * $perPage);

        // Ergebnisse abrufen
        $data = $this->get();

        // Paginierungsinformationen berechnen
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min($page, $lastPage);
        $hasMorePages = $currentPage < $lastPage;
        $nextPage = $hasMorePages ? $currentPage + 1 : null;
        $prevPage = $currentPage > 1 ? $currentPage - 1 : null;

        $this->logger->debug("Paginierung durchgeführt", [
            'connection' => $this->connectionName,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage
        ]);

        // Paginierungsergebnis erstellen und zurückgeben
        return new PaginationResult(
            data: $data,
            total: $total,
            perPage: $perPage,
            currentPage: $currentPage,
            lastPage: $lastPage,
            nextPage: $nextPage,
            prevPage: $prevPage,
            from: ($currentPage - 1) * $perPage + 1,
            to: min($currentPage * $perPage, $total)
        );
    }

    /**
     * Paginiert die Ergebnisse mit einem einfachen Cursor
     *
     * @param string $column Spalte für den Cursor
     * @param string|int|null $cursor Aktueller Cursor-Wert
     * @param int $perPage Anzahl der Elemente pro Seite
     * @param bool $descending True für absteigende Sortierung
     * @return CursorPaginationResult Cursor-Paginierungsergebnis
     */
    public function cursorPaginate(
        string $column,
        string|int|null $cursor = null,
        int $perPage = 15,
        bool $descending = false
    ): CursorPaginationResult {
        if ($perPage < 1) {
            throw new InvalidArgumentException("Elemente pro Seite muss positiv sein");
        }

        // Sortierrichtung basierend auf $descending Parameter
        $direction = $descending ? 'DESC' : 'ASC';

        // Aktuelle Abfrage klonen
        $originalQuery = clone $this;

        // WHERE-Bedingung für Cursor hinzufügen, falls Cursor vorhanden
        if ($cursor !== null) {
            $operator = $descending ? '<' : '>';
            $this->where($column, $operator, $cursor);
        }

        // Nach Cursor-Spalte sortieren und Limit setzen
        $this->orderBy($column, $descending ? 'DESC' : 'ASC')
            ->limit($perPage + 1); // Einen zusätzlichen Datensatz für "hasMore" abfragen

        // Ergebnisse abrufen
        $results = $this->get();

        // Prüfen, ob es weitere Seiten gibt
        $hasMore = count($results) > $perPage;

        // Überzähligen Datensatz entfernen, falls vorhanden
        if ($hasMore) {
            array_pop($results);
        }

        // Nächsten und vorherigen Cursor ermitteln
        $nextCursor = null;
        $prevCursor = null;

        if ($hasMore && !empty($results)) {
            $lastItem = end($results);
            $nextCursor = $lastItem[$column] ?? null;
        }

        // Vorherigen Cursor ermitteln, falls ein aktueller Cursor vorhanden ist
        if ($cursor !== null) {
            // Abfrage für vorherigen Cursor
            $prevQuery = clone $originalQuery;
            $operator = $descending ? '>' : '<';

            $prevQuery->where($column, $operator, $cursor)
                ->orderBy($column, $descending ? 'ASC' : 'DESC')
                ->limit(1);

            $prevItem = $prevQuery->first();

            if ($prevItem !== null) {
                $prevCursor = $prevItem[$column] ?? null;
            }
        }

        $this->logger->debug("Cursor-Paginierung durchgeführt", [
            'connection' => $this->connectionName,
            'cursor' => $cursor,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'prev_cursor' => $prevCursor
        ]);

        // Cursor-Paginierungsergebnis erstellen und zurückgeben
        return new CursorPaginationResult(
            data: $results,
            perPage: $perPage,
            cursor: $cursor,
            nextCursor: $nextCursor,
            prevCursor: $prevCursor,
            hasMore: $hasMore
        );
    }

    /**
     * Führt eine einfache Paginierung mit nur Vorwärts/Rückwärts-Navigation durch
     *
     * @param int $page Seitennummer (1-basiert)
     * @param int $perPage Anzahl der Elemente pro Seite
     * @return SimplePaginationResult Einfaches Paginierungsergebnis ohne Gesamtzahl
     */
    public function simplePaginate(int $page = 1, int $perPage = 15): SimplePaginationResult
    {
        if ($page < 1) {
            throw new InvalidArgumentException("Seitennummer muss positiv sein");
        }

        if ($perPage < 1) {
            throw new InvalidArgumentException("Elemente pro Seite muss positiv sein");
        }

        // LIMIT und OFFSET für Paginierung setzen
        $this->limit($perPage + 1)->offset(($page - 1) * $perPage);

        // Ergebnisse abrufen
        $results = $this->get();

        // Prüfen, ob es weitere Seiten gibt
        $hasMore = count($results) > $perPage;

        // Überzähligen Datensatz entfernen, falls vorhanden
        if ($hasMore) {
            array_pop($results);
        }

        $this->logger->debug("Einfache Paginierung durchgeführt", [
            'connection' => $this->connectionName,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore
        ]);

        // Einfaches Paginierungsergebnis erstellen und zurückgeben
        return new SimplePaginationResult(
            data: $results,
            perPage: $perPage,
            currentPage: $page,
            hasMore: $hasMore,
            nextPage: $hasMore ? $page + 1 : null,
            prevPage: $page > 1 ? $page - 1 : null
        );
    }

    /**
     * Führt Chunking der Abfrageergebnisse für effiziente Verarbeitung großer Datenmengen durch
     *
     * @param int $count Anzahl der Datensätze pro Chunk
     * @param callable $callback Callback-Funktion, die für jeden Chunk aufgerufen wird
     * @return bool True, wenn alle Datensätze verarbeitet wurden, False wenn der Callback false zurückgibt
     */
    public function chunk(int $count, callable $callback): bool
    {
        if ($count < 1) {
            throw new InvalidArgumentException("Chunk-Größe muss positiv sein");
        }

        $page = 1;

        do {
            // Aktuelle Abfrage klonen, damit die Hauptabfrage nicht verändert wird
            $query = clone $this;

            // Daten für aktuellen Chunk abrufen
            $results = $query->limit($count)->offset(($page - 1) * $count)->get();

            // Wenn keine Ergebnisse mehr, Schleife beenden
            if (empty($results)) {
                break;
            }

            $this->logger->debug("Chunk verarbeitet", [
                'connection' => $this->connectionName,
                'page' => $page,
                'count' => $count,
                'results' => count($results)
            ]);

            // Callback mit aktuellem Chunk aufrufen
            $result = $callback($results, $page);

            // Wenn Callback false zurückgibt, Schleife beenden
            if ($result === false) {
                return false;
            }

            $page++;

        } while (count($results) === $count);

        return true;
    }
}