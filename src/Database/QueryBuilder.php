<?php

namespace Src\Database;

use AllowDynamicProperties;
use Closure;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Src\Database\Cache\CacheInterface;
use Src\Database\Cache\NullCache;
use Src\Database\Enums\ConnectionMode;
use Src\Database\Enums\JoinType;
use Src\Database\Enums\OrderDirection;
use Src\Database\Enums\SqlOperator;
use Src\Database\Exceptions\QueryException;
use Src\Database\Traits\PaginationTrait;
use Src\Database\Traits\TransactionTrait;
use Src\Database\Traits\QueryBuilderAnonymizationTrait;
use Src\Database\Anonymization\AnonymizationService;
use Src\Log\LoggerInterface;

/**
 * Fluent Query Builder für MySQL mit Typsicherheit
 *
 * Ermöglicht die einfache Erstellung von SQL-Abfragen mit einem Fluent Interface.
 */
#[AllowDynamicProperties] class QueryBuilder
{
    use TransactionTrait;
    use PaginationTrait;
    use QueryBuilderAnonymizationTrait;

    /** @var string Tabelle, auf die zugegriffen wird */
    private string $table = '';

    /** @var array<string> Spalten, die ausgewählt werden sollen */
    private array $columns = ['*'];

    /** @var array<array> WHERE-Bedingungen */
    private array $wheres = [];

    /** @var array<array> JOIN-Klauseln */
    private array $joins = [];

    /** @var array<string> GROUP BY-Klauseln */
    private array $groups = [];

    /** @var array<array> HAVING-Bedingungen */
    private array $havings = [];

    /** @var array<array> ORDER BY-Klauseln */
    private array $orders = [];

    /** @var int|null LIMIT-Klausel */
    private ?int $limit = null;

    /** @var int|null OFFSET-Klausel */
    private ?int $offset = null;

    /** @var array<string, mixed> Parameter für Prepared Statements */
    private array $bindings = [];

    /** @var CacheInterface Cache für Query-Ergebnisse */
    private CacheInterface $cache;

    /** @var string|null Cache-Key für diese Abfrage */
    private ?string $cacheKey = null;

    /** @var int|null Cache-Lebensdauer in Sekunden */
    private ?int $cacheTtl = null;

    /** @var string|null Ein zu verwendender Index-Hint */
    private ?string $indexHint = null;

    /**
     * Erstellt einen neuen Query Builder
     *
     * @param ConnectionManager $connectionManager Manager für Datenbankverbindungen
     * @param string $connectionName Name der zu verwendenden Verbindung
     * @param LoggerInterface $logger Logger für Datenbankoperationen
     * @param CacheInterface|null $cache Cache für Abfrageergebnisse (optional)
     */
    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly string $connectionName,
        private readonly LoggerInterface $logger,
        ?CacheInterface $cache = null
    ) {
        $this->cache = $cache ?? new NullCache();
    }

    /**
     * Beginnt eine neue Abfrage auf einer Tabelle
     *
     * @param string $table Tabellenname
     * @return self
     */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Aliasiert eine Tabelle
     *
     * @param string $alias Tabellenalias
     * @return self
     */
    public function as(string $alias): self
    {
        $this->table = "{$this->table} AS {$alias}";
        return $this;
    }

    /**
     * Setzt die Spalten, die ausgewählt werden sollen
     *
     * @param string|array<string> $columns Spalten (komma-separierter String oder Array)
     * @return self
     */
    public function select(string|array $columns = ['*']): self
    {
        $this->columns = is_array($columns) ? $columns : explode(',', $columns);

        // Leerzeichen trimmen, falls als String übergeben
        if (!is_array($columns)) {
            $this->columns = array_map('trim', $this->columns);
        }

        return $this;
    }

    /**
     * Fügt einen Index-Hint hinzu
     *
     * @param string $indexName Name des Index
     * @return self
     */
    public function useIndex(string $indexName): self
    {
        $this->indexHint = "USE INDEX ({$indexName})";
        return $this;
    }

    /**
     * Fügt einen Index-Hint hinzu, der MySQL anweist, einen bestimmten Index nicht zu verwenden
     *
     * @param string $indexName Name des Index
     * @return self
     */
    public function ignoreIndex(string $indexName): self
    {
        $this->indexHint = "IGNORE INDEX ({$indexName})";
        return $this;
    }

    /**
     * Fügt einen Index-Hint hinzu, der MySQL zwingt, einen bestimmten Index zu verwenden
     *
     * @param string $indexName Name des Index
     * @return self
     */
    public function forceIndex(string $indexName): self
    {
        $this->indexHint = "FORCE INDEX ({$indexName})";
        return $this;
    }

    /**
     * Setzt eine MAX-Funktion
     *
     * @param string $column Spaltenname
     * @param string|null $alias Alias für das Ergebnis
     * @return self
     */
    public function max(string $column, ?string $alias = null): self
    {
        $this->aggregate('MAX', $column, $alias);
        return $this;
    }

    /**
     * Setzt eine MIN-Funktion
     *
     * @param string $column Spaltenname
     * @param string|null $alias Alias für das Ergebnis
     * @return self
     */
    public function min(string $column, ?string $alias = null): self
    {
        $this->aggregate('MIN', $column, $alias);
        return $this;
    }

    /**
     * Setzt eine AVG-Funktion
     *
     * @param string $column Spaltenname
     * @param string|null $alias Alias für das Ergebnis
     * @return self
     */
    public function avg(string $column, ?string $alias = null): self
    {
        $this->aggregate('AVG', $column, $alias);
        return $this;
    }

    /**
     * Setzt eine SUM-Funktion
     *
     * @param string $column Spaltenname
     * @param string|null $alias Alias für das Ergebnis
     * @return self
     */
    public function sum(string $column, ?string $alias = null): self
    {
        $this->aggregate('SUM', $column, $alias);
        return $this;
    }

    /**
     * Hilfsmethode für Aggregat-Funktionen
     *
     * @param string $function Name der Funktion (MAX, MIN, AVG, SUM, COUNT)
     * @param string $column Spaltenname
     * @param string|null $alias Alias für das Ergebnis
     * @return void
     */
    private function aggregate(string $function, string $column, ?string $alias = null): void
    {
        $this->columns = [];

        $columnExpression = "{$function}({$column})";

        if ($alias !== null) {
            $columnExpression .= " AS {$alias}";
        }

        $this->columns[] = $columnExpression;
    }

    /**
     * Erzeugt eine DISTINCT-Klausel
     *
     * @return self
     */
    public function distinct(): self
    {
        $this->columns = array_map(function ($column) {
            if ($column === '*') {
                return $column;
            }

            if (stripos($column, 'DISTINCT') === false) {
                return "DISTINCT {$column}";
            }

            return $column;
        }, $this->columns);

        return $this;
    }

    /**
     * Fügt eine WHERE-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @param mixed $operator Operator oder Wert (bei Weglassen wird '=' angenommen)
     * @param mixed $value Wert (optional wenn $operator als Wert verwendet wird)
     * @return self
     */
    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        // Wenn nur zwei Parameter übergeben wurden, verwende den zweiten als Wert und '=' als Operator
        if ($value === null) {
            $value = $operator;
            $operator = SqlOperator::EQUAL;
        }

        // Enum-Wert verarbeiten
        if ($operator instanceof SqlOperator) {
            $operator = $operator->value;
        }

        // Operator validieren
        $validOperators = array_map(fn($case) => $case->value, SqlOperator::cases());
        if (!in_array($operator, $validOperators, true)) {
            throw new InvalidArgumentException("Ungültiger SQL-Operator: {$operator}");
        }

        $type = 'basic';

        // Spezialbehandlung für NULL-Werte
        if ($value === null) {
            if ($operator === SqlOperator::EQUAL->value) {
                $operator = SqlOperator::IS_NULL->value;
                $value = null;
            } elseif ($operator === SqlOperator::NOT_EQUAL->value) {
                $operator = SqlOperator::IS_NOT_NULL->value;
                $value = null;
            }
        }

        $this->wheres[] = compact('type', 'column', 'operator', 'value');

        return $this;
    }

    /**
     * Fügt eine WHERE-Klausel mit OR-Verknüpfung hinzu
     *
     * @param string $column Spaltenname
     * @param mixed $operator Operator oder Wert (bei Weglassen wird '=' angenommen)
     * @param mixed $value Wert (optional wenn $operator als Wert verwendet wird)
     * @return self
     */
    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        // Wenn nur zwei Parameter übergeben wurden, verwende den zweiten als Wert und '=' als Operator
        if ($value === null) {
            $value = $operator;
            $operator = SqlOperator::EQUAL;
        }

        // Enum-Wert verarbeiten
        if ($operator instanceof SqlOperator) {
            $operator = $operator->value;
        }

        // Operator validieren
        $validOperators = array_map(fn($case) => $case->value, SqlOperator::cases());
        if (!in_array($operator, $validOperators, true)) {
            throw new InvalidArgumentException("Ungültiger SQL-Operator: {$operator}");
        }

        $type = 'or';

        // Spezialbehandlung für NULL-Werte
        if ($value === null) {
            if ($operator === SqlOperator::EQUAL->value) {
                $operator = SqlOperator::IS_NULL->value;
                $value = null;
            } elseif ($operator === SqlOperator::NOT_EQUAL->value) {
                $operator = SqlOperator::IS_NOT_NULL->value;
                $value = null;
            }
        }

        $this->wheres[] = compact('type', 'column', 'operator', 'value');

        return $this;
    }

    /**
     * Fügt eine WHERE IN-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @param array $values Array von Werten
     * @return self
     */
    public function whereIn(string $column, array $values): self
    {
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
        ];

        return $this;
    }

    /**
     * Fügt eine WHERE NOT IN-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @param array $values Array von Werten
     * @return self
     */
    public function whereNotIn(string $column, array $values): self
    {
        $this->wheres[] = [
            'type' => 'notIn',
            'column' => $column,
            'values' => $values,
        ];

        return $this;
    }

    /**
     * Fügt eine WHERE BETWEEN-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @param mixed $min Minimalwert
     * @param mixed $max Maximalwert
     * @return self
     */
    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'min' => $min,
            'max' => $max,
        ];

        return $this;
    }

    /**
     * Fügt eine WHERE NOT BETWEEN-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @param mixed $min Minimalwert
     * @param mixed $max Maximalwert
     * @return self
     */
    public function whereNotBetween(string $column, mixed $min, mixed $max): self
    {
        $this->wheres[] = [
            'type' => 'notBetween',
            'column' => $column,
            'min' => $min,
            'max' => $max,
        ];

        return $this;
    }

    /**
     * Fügt eine WHERE NULL-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @return self
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
        ];

        return $this;
    }

    /**
     * Fügt eine WHERE NOT NULL-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @return self
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'notNull',
            'column' => $column,
        ];

        return $this;
    }

    /**
     * Fügt eine verschachtelte WHERE-Klausel hinzu
     *
     * @param Closure $callback Callback-Funktion, die einen neuen QueryBuilder erhält
     * @return self
     */
    public function whereNested(Closure $callback): self
    {
        $query = new self($this->connectionManager, $this->connectionName, $this->logger, $this->cache);

        call_user_func($callback, $query);

        $this->wheres[] = [
            'type' => 'nested',
            'query' => $query,
        ];

        return $this;
    }

    /**
     * Fügt eine verschachtelte WHERE-Klausel mit OR-Verknüpfung hinzu
     *
     * @param Closure $callback Callback-Funktion, die einen neuen QueryBuilder erhält
     * @return self
     */
    public function orWhereNested(Closure $callback): self
    {
        $query = new self($this->connectionManager, $this->connectionName, $this->logger, $this->cache);

        call_user_func($callback, $query);

        $this->wheres[] = [
            'type' => 'orNested',
            'query' => $query,
        ];

        return $this;
    }

    /**
     * Fügt eine WHERE RAW-Klausel hinzu
     *
     * @param string $sql SQL-Ausdruck
     * @param array $bindings Parameter-Bindings
     * @return self
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * Fügt eine JOIN-Klausel hinzu
     *
     * @param string $table Tabellenname für den Join
     * @param string $first Erste Join-Bedingung (Spalte der aktuellen Tabelle)
     * @param string $operator Join-Operator (=, <, >, etc.)
     * @param string $second Zweite Join-Bedingung (Spalte der Join-Tabelle)
     * @param JoinType $type Join-Typ (INNER, LEFT, RIGHT, etc.)
     * @return self
     */
    public function join(
        string $table,
        string $first,
        string $operator,
        string $second,
        JoinType $type = JoinType::INNER
    ): self {
        $this->joins[] = [
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'type' => $type->value,
        ];

        return $this;
    }

    /**
     * Fügt eine INNER JOIN-Klausel hinzu
     *
     * @param string $table Tabellenname für den Join
     * @param string $first Erste Join-Bedingung (Spalte der aktuellen Tabelle)
     * @param string $operator Join-Operator (=, <, >, etc.)
     * @param string $second Zweite Join-Bedingung (Spalte der Join-Tabelle)
     * @return self
     */
    public function innerJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, JoinType::INNER);
    }

    /**
     * Fügt eine LEFT JOIN-Klausel hinzu
     *
     * @param string $table Tabellenname für den Join
     * @param string $first Erste Join-Bedingung (Spalte der aktuellen Tabelle)
     * @param string $operator Join-Operator (=, <, >, etc.)
     * @param string $second Zweite Join-Bedingung (Spalte der Join-Tabelle)
     * @return self
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, JoinType::LEFT);
    }

    /**
     * Fügt eine RIGHT JOIN-Klausel hinzu
     *
     * @param string $table Tabellenname für den Join
     * @param string $first Erste Join-Bedingung (Spalte der aktuellen Tabelle)
     * @param string $operator Join-Operator (=, <, >, etc.)
     * @param string $second Zweite Join-Bedingung (Spalte der Join-Tabelle)
     * @return self
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, JoinType::RIGHT);
    }

    /**
     * Fügt eine GROUP BY-Klausel hinzu
     *
     * @param string|array $columns Spalten für GROUP BY
     * @return self
     */
    public function groupBy(string|array $columns): self
    {
        $columns = is_array($columns) ? $columns : explode(',', $columns);
        $this->groups = array_merge($this->groups, array_map('trim', $columns));

        return $this;
    }

    /**
     * Fügt eine HAVING-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @param mixed $operator Operator oder Wert (bei Weglassen wird '=' angenommen)
     * @param mixed $value Wert (optional wenn $operator als Wert verwendet wird)
     * @return self
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): self
    {
        // Wenn nur zwei Parameter übergeben wurden, verwende den zweiten als Wert und '=' als Operator
        if ($value === null) {
            $value = $operator;
            $operator = SqlOperator::EQUAL;
        }

        // Enum-Wert verarbeiten
        if ($operator instanceof SqlOperator) {
            $operator = $operator->value;
        }

        $type = 'basic';

        $this->havings[] = compact('type', 'column', 'operator', 'value');

        return $this;
    }

    /**
     * Fügt eine ORDER BY-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @param OrderDirection $direction Sortierrichtung (ASC oder DESC)
     * @return self
     */
    public function orderBy(string $column, OrderDirection $direction = OrderDirection::ASC): self
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => $direction->value,
        ];

        return $this;
    }

    /**
     * Fügt eine ORDER BY DESC-Klausel hinzu
     *
     * @param string $column Spaltenname
     * @return self
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, OrderDirection::DESC);
    }

    /**
     * Setzt eine LIMIT-Klausel
     *
     * @param int $limit Limit
     * @return self
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Setzt eine OFFSET-Klausel
     *
     * @param int $offset Offset
     * @return self
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Aktiviert das Caching für diese Abfrage
     *
     * @param string|null $key Optionaler Cache-Key (wird automatisch generiert, wenn nicht angegeben)
     * @param int $ttl Cache-Lebensdauer in Sekunden (null für unbegrenzt)
     * @return self
     */
    public function cache(?string $key = null, int $ttl = 3600): self
    {
        $this->cacheTtl = $ttl;
        $this->cacheKey = $key;

        return $this;
    }

    /**
     * Generiert einen eindeutigen Cache-Key für die aktuelle Abfrage
     *
     * @return string Cache-Key
     */
    private function generateCacheKey(): string
    {
        if ($this->cacheKey !== null) {
            return 'query_' . $this->connectionName . '_' . $this->cacheKey;
        }

        // Hash aus der SQL-Abfrage und Parametern erstellen
        $sql = $this->toSql();
        $params = $this->getBindings();

        return 'query_' . $this->connectionName . '_' . md5($sql . serialize($params));
    }

    /**
     * Führt eine SELECT-Abfrage aus und gibt alle Ergebnisse zurück
     *
     * @return array<array<string, mixed>> Array mit Ergebniszeilendatensätzen
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function get(): array
    {
        // Wenn Caching aktiviert ist, prüfen ob Ergebnis bereits im Cache ist
        if ($this->cacheTtl !== null) {
            $cacheKey = $this->generateCacheKey();
            $cachedResult = $this->cache->get($cacheKey);

            if ($cachedResult !== null) {
                $this->logger->debug("Abfrageergebnis aus Cache geladen", [
                    'connection' => $this->connectionName,
                    'cache_key' => $cacheKey
                ]);

                // Anonymisierung auch auf gecachte Ergebnisse anwenden
                return $this->anonymizationEnabled ? $this->anonymizeResults($cachedResult) : $cachedResult;
            }
        }

        // SQL-Abfrage generieren
        $query = $this->toSql();
        $bindings = $this->getBindings();

        // Abfrage ausführen
        try {
            $stmt = $this->executeQuery($query, $bindings, ConnectionMode::READ);
            $result = $stmt->fetchAll();

            // Ergebnis cachen, wenn Caching aktiviert ist
            if ($this->cacheTtl !== null) {
                $cacheKey = $this->generateCacheKey();

                // Originaldaten im Cache speichern (nicht die anonymisierten)
                $this->cache->set($cacheKey, $result, $this->cacheTtl);

                $this->logger->debug("Abfrageergebnis in Cache gespeichert", [
                    'connection' => $this->connectionName,
                    'cache_key' => $cacheKey,
                    'ttl' => $this->cacheTtl
                ]);
            }

            // Anonymisierung anwenden, falls aktiviert
            return $this->anonymizationEnabled ? $this->anonymizeResults($result) : $result;
        } catch (\PDOException $e) {
            $this->logger->error("Fehler bei SELECT-Abfrage", [
                'connection' => $this->connectionName,
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            throw new QueryException("Fehler bei SELECT-Abfrage: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Führt eine SELECT-Abfrage aus und gibt die erste Ergebniszeile zurück
     *
     * @return array<string, mixed>|null Ergebniszeile oder null, wenn keine Ergebnisse gefunden wurden
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function first(): ?array
    {
        // Limit auf 1 setzen, um nur die erste Zeile abzurufen
        $this->limit = 1;

        $results = $this->get();
        $result = $results[0] ?? null;

        // Hier keine explizite Anonymisierung nötig, da get() bereits die Anonymisierung anwendet
        return $result;
    }

    /**
     * Führt eine SELECT-Abfrage aus und gibt eine einzelne Zelle zurück
     *
     * @param string|null $column Optionaler Spaltenname (verwendet die erste Spalte, wenn nicht angegeben)
     * @return mixed Wert der Zelle oder null, wenn keine Ergebnisse gefunden wurden
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function value(?string $column = null): mixed
    {
        // Wenn eine Spalte angegeben wurde, nur diese auswählen
        if ($column !== null) {
            $this->columns = [$column];
        }

        $result = $this->first();

        if ($result === null) {
            return null;
        }

        // Spaltenname ermitteln (erste Spalte, wenn nicht explizit angegeben)
        if ($column === null) {
            $column = array_key_first($result);
        }

        return $result[$column] ?? null;
    }

    /**
     * Führt eine SELECT-Abfrage aus und gibt eine einzelne Spalte zurück
     *
     * @param string|null $column Optionaler Spaltenname (verwendet die erste Spalte, wenn nicht angegeben)
     * @return array<mixed> Array mit den Werten der Spalte
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function pluck(?string $column = null): array
    {
        // Wenn eine Spalte angegeben wurde, nur diese auswählen
        if ($column !== null) {
            $this->columns = [$column];
        }

        $results = $this->get();
        $values = [];

        if (empty($results)) {
            return $values;
        }

        // Spaltenname ermitteln (erste Spalte, wenn nicht explizit angegeben)
        if ($column === null) {
            $column = array_key_first($results[0]);
        }

        foreach ($results as $row) {
            $values[] = $row[$column] ?? null;
        }

        return $values;
    }

    /**
     * Führt eine SELECT-Abfrage aus und gibt Werte einer Spalte als Schlüssel und einer anderen Spalte als Werte zurück
     *
     * @param string $value Spaltenname für die Werte
     * @param string $key Spaltenname für die Schlüssel
     * @return array<mixed, mixed> Assoziatives Array mit Schlüssel-Wert-Paaren
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function pluckWithKeys(string $value, string $key): array
    {
        // Beide Spalten auswählen
        $this->columns = [$key, $value];

        $results = $this->get();
        $values = [];

        foreach ($results as $row) {
            $keyValue = $row[$key] ?? null;
            $valueValue = $row[$value] ?? null;

            if ($keyValue !== null) {
                $values[$keyValue] = $valueValue;
            }
        }

        return $values;
    }

    /**
     * Führt eine Zählung der Ergebnisse durch
     *
     * @param string $column Spalte für die Zählung (Standard: '*')
     * @return int Anzahl der Ergebnisse
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function count(string $column = '*'): int
    {
        $original = $this->columns;

        $this->columns = ["COUNT({$column}) as aggregate"];

        $result = $this->first();

        $this->columns = $original;

        return (int) ($result['aggregate'] ?? 0);
    }

    /**
     * Prüft, ob die Abfrage Ergebnisse liefert
     *
     * @return bool True, wenn Ergebnisse vorhanden sind, sonst False
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Fügt einen neuen Datensatz ein
     *
     * @param array<string, mixed> $values Zu speichernde Werte als Schlüssel-Wert-Paare
     * @return int|string ID des eingefügten Datensatzes (bei Auto-Increment) oder Anzahl der eingefügten Zeilen
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function insert(array $values): int|string
    {
        $query = $this->compileInsert($values);
        $bindings = $this->prepareBindings($values);

        try {
            $stmt = $this->executeQuery($query, $bindings, ConnectionMode::WRITE);

            $connection = $this->connectionManager->getConnection($this->connectionName, true);
            $insertId = $connection->lastInsertId();

            // Cache invalidieren, wenn Caching aktiviert ist
            $this->invalidateTableCache();

            return $insertId ?: $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->logger->error("Fehler bei INSERT-Abfrage", [
                'connection' => $this->connectionName,
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            throw new QueryException("Fehler bei INSERT-Abfrage: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Fügt mehrere Datensätze auf einmal ein
     *
     * @param array<array<string, mixed>> $values Array mit Arrays von Werten
     * @return int Anzahl der eingefügten Zeilen
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function insertMany(array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        // Spaltennamen aus dem ersten Eintrag extrahieren
        $columns = array_keys($values[0]);

        $query = $this->compileInsertMany($columns, count($values));
        $bindings = [];

        // Werte für alle Einträge flach in ein Binding-Array packen
        foreach ($values as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column] ?? null;
            }
        }

        try {
            $stmt = $this->executeQuery($query, $bindings, ConnectionMode::WRITE);

            // Cache invalidieren
            $this->invalidateTableCache();

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->logger->error("Fehler bei INSERT MANY-Abfrage", [
                'connection' => $this->connectionName,
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            throw new QueryException("Fehler bei INSERT MANY-Abfrage: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Aktualisiert vorhandene Datensätze
     *
     * @param array<string, mixed> $values Zu aktualisierende Werte als Schlüssel-Wert-Paare
     * @return int Anzahl der aktualisierten Zeilen
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function update(array $values): int
    {
        $query = $this->compileUpdate($values);
        $bindings = array_merge($this->prepareBindings($values), $this->getBindings());

        try {
            $stmt = $this->executeQuery($query, $bindings, ConnectionMode::WRITE);

            // Cache invalidieren
            $this->invalidateTableCache();

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->logger->error("Fehler bei UPDATE-Abfrage", [
                'connection' => $this->connectionName,
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            throw new QueryException("Fehler bei UPDATE-Abfrage: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Inkrementiert einen Wert in einer Spalte
     *
     * @param string $column Spaltenname
     * @param int $amount Inkrementierungswert (Standard: 1)
     * @return int Anzahl der aktualisierten Zeilen
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function increment(string $column, int $amount = 1): int
    {
        return $this->updateRaw("`{$column}` = `{$column}` + ?", [$amount]);
    }

    /**
     * Dekrementiert einen Wert in einer Spalte
     *
     * @param string $column Spaltenname
     * @param int $amount Dekrementierungswert (Standard: 1)
     * @return int Anzahl der aktualisierten Zeilen
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function decrement(string $column, int $amount = 1): int
    {
        return $this->updateRaw("`{$column}` = `{$column}` - ?", [$amount]);
    }

    /**
     * Führt eine Raw-Update-Abfrage aus
     *
     * @param string $expression SQL-Ausdruck für SET
     * @param array $bindings Parameter-Bindings für den Ausdruck
     * @return int Anzahl der aktualisierten Zeilen
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function updateRaw(string $expression, array $bindings = []): int
    {
        $query = $this->compileUpdateRaw($expression);
        $mergedBindings = array_merge($bindings, $this->getBindings());

        try {
            $stmt = $this->executeQuery($query, $mergedBindings, ConnectionMode::WRITE);

            // Cache invalidieren
            $this->invalidateTableCache();

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->logger->error("Fehler bei UPDATE RAW-Abfrage", [
                'connection' => $this->connectionName,
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            throw new QueryException("Fehler bei UPDATE RAW-Abfrage: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Löscht Datensätze
     *
     * @return int Anzahl der gelöschten Zeilen
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function delete(): int
    {
        $query = $this->compileDelete();
        $bindings = $this->getBindings();

        try {
            $stmt = $this->executeQuery($query, $bindings, ConnectionMode::WRITE);

            // Cache invalidieren
            $this->invalidateTableCache();

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->logger->error("Fehler bei DELETE-Abfrage", [
                'connection' => $this->connectionName,
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            throw new QueryException("Fehler bei DELETE-Abfrage: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Führt eine TRUNCATE-Abfrage aus (löscht alle Datensätze und setzt Auto-Increment zurück)
     *
     * @return bool True bei Erfolg
     * @throws QueryException Bei Fehlern in der Abfrage
     */
    public function truncate(): bool
    {
        $query = "TRUNCATE TABLE {$this->table}";

        try {
            $this->executeQuery($query, [], ConnectionMode::WRITE);

            // Cache invalidieren
            $this->invalidateTableCache();

            return true;
        } catch (\PDOException $e) {
            $this->logger->error("Fehler bei TRUNCATE-Abfrage", [
                'connection' => $this->connectionName,
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            throw new QueryException("Fehler bei TRUNCATE-Abfrage: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Erzeugt ein Future-Objekt für asynchrone Abfragen
     *
     * @return Future Asynchrones Future-Objekt
     */
    public function async(): Future
    {
        return new Future($this);
    }

    /**
     * Führt eine SQL-Abfrage aus
     *
     * @param string $query SQL-Abfrage
     * @param array $bindings Parameter-Bindings
     * @param ConnectionMode $mode Verbindungsmodus (READ oder WRITE)
     * @return PDOStatement
     * @throws \PDOException Bei Fehlern in der Abfrage
     */
    private function executeQuery(string $query, array $bindings, ConnectionMode $mode): PDOStatement
    {
        $this->logger->debug("Führe SQL-Abfrage aus", [
            'connection' => $this->connectionName,
            'mode' => $mode->name,
            'query' => $query
        ]);

        $connection = $this->connectionManager->getConnection(
            $this->connectionName,
            $mode === ConnectionMode::WRITE
        );

        $statement = $connection->prepare($query);

        // Parameter binden
        foreach ($bindings as $key => $value) {
            $paramType = $this->getParamType($value);

            if (is_int($key)) {
                // Positionsparameter (?), 1-basierter Index
                $statement->bindValue($key + 1, $value, $paramType);
            } else {
                // Benannter Parameter (:name)
                $statement->bindValue($key, $value, $paramType);
            }
        }

        $statement->execute();

        return $statement;
    }

    /**
     * Ermittelt den PDO-Parametertyp für einen Wert
     *
     * @param mixed $value Zu bindender Wert
     * @return int PDO-Parametertyp
     */
    private function getParamType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * Bereitet Bindings für eine Abfrage vor
     *
     * @param array $values Zu bindende Werte
     * @return array Vorbereitete Bindings
     */
    private function prepareBindings(array $values): array
    {
        $bindings = [];

        foreach ($values as $key => $value) {
            $bindings[":$key"] = $value;
        }

        return $bindings;
    }

    /**
     * Gibt die aktuellen WHERE-Bindings zurück
     *
     * @return array Bindings für WHERE-Klauseln
     */
    private function getBindings(): array
    {
        $bindings = [];

        // Where-Bedingungen
        foreach ($this->wheres as $where) {
            switch ($where['type']) {
                case 'basic':
                case 'or':
                    // Operator, der keinen Wert benötigt
                    if (in_array($where['operator'], [SqlOperator::IS_NULL->value, SqlOperator::IS_NOT_NULL->value])) {
                        break;
                    }
                    $bindings[] = $where['value'];
                    break;

                case 'in':
                case 'notIn':
                    $bindings = array_merge($bindings, $where['values']);
                    break;

                case 'between':
                case 'notBetween':
                    $bindings[] = $where['min'];
                    $bindings[] = $where['max'];
                    break;

                case 'nested':
                case 'orNested':
                    // Alle Bindings der verschachtelten Abfrage hinzufügen
                    /** @var QueryBuilder $query */
                    $query = $where['query'];
                    $bindings = array_merge($bindings, $query->getBindings());
                    break;

                case 'raw':
                    // Bindings für Raw-SQL hinzufügen
                    $bindings = array_merge($bindings, $where['bindings']);
                    break;
            }
        }

        // Having-Bedingungen
        foreach ($this->havings as $having) {
            if ($having['type'] === 'basic' && $having['value'] !== null) {
                $bindings[] = $having['value'];
            }
        }

        return $bindings;
    }

    /**
     * Generiert eine SELECT-Abfrage ohne sie auszuführen
     *
     * @return string SQL-Abfrage
     */
    public function toSql(): string
    {
        // Prüfen, ob eine Tabelle angegeben wurde
        if (empty($this->table)) {
            throw new InvalidArgumentException("Keine Tabelle für die Abfrage angegeben");
        }

        $query = $this->compileSelect();

        $this->logger->debug("SQL-Abfrage generiert", [
            'connection' => $this->connectionName,
            'query' => $query
        ]);

        return $query;
    }

    /**
     * Kompiliert eine SELECT-Abfrage
     *
     * @return string SQL-Abfrage
     */
    private function compileSelect(): string
    {
        $components = [];

        // SELECT-Teil
        $columns = implode(', ', $this->columns);
        $components[] = "SELECT {$columns}";

        // FROM-Teil mit Index-Hint
        $table = $this->table;
        if ($this->indexHint !== null) {
            $table .= " {$this->indexHint}";
        }
        $components[] = "FROM {$table}";

        // JOIN-Teil
        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $components[] = "{$join['type']} {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }

        // WHERE-Teil
        if (!empty($this->wheres)) {
            $whereSql = $this->compileWheres();
            if (!empty($whereSql)) {
                $components[] = "WHERE {$whereSql}";
            }
        }

        // GROUP BY-Teil
        if (!empty($this->groups)) {
            $groups = implode(', ', $this->groups);
            $components[] = "GROUP BY {$groups}";
        }

        // HAVING-Teil
        if (!empty($this->havings)) {
            $havingSql = $this->compileHavings();
            if (!empty($havingSql)) {
                $components[] = "HAVING {$havingSql}";
            }
        }

        // ORDER BY-Teil
        if (!empty($this->orders)) {
            $orders = [];
            foreach ($this->orders as $order) {
                $orders[] = "{$order['column']} {$order['direction']}";
            }
            $orderStr = implode(', ', $orders);
            $components[] = "ORDER BY {$orderStr}";
        }

        // LIMIT-Teil
        if ($this->limit !== null) {
            $components[] = "LIMIT {$this->limit}";
        }

        // OFFSET-Teil
        if ($this->offset !== null) {
            $components[] = "OFFSET {$this->offset}";
        }

        return implode(' ', $components);
    }

    /**
     * Kompiliert WHERE-Klauseln
     *
     * @return string SQL für WHERE-Klauseln
     */
    private function compileWheres(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        $wheres = [];
        $boolean = 'AND';

        foreach ($this->wheres as $i => $where) {
            $type = $where['type'];

            // Verknüpfungsoperator bestimmen
            if ($i > 0) {
                $boolean = in_array($type, ['or', 'orNested']) ? 'OR' : 'AND';
            } else {
                $boolean = ''; // Kein Operator für die erste Bedingung
            }

            $sql = '';

            switch ($type) {
                case 'basic':
                case 'or':
                    $column = $where['column'];
                    $operator = $where['operator'];

                    // Operator ohne Wert
                    if (in_array($operator, [SqlOperator::IS_NULL->value, SqlOperator::IS_NOT_NULL->value])) {
                        $sql = "{$column} {$operator}";
                    } else {
                        $sql = "{$column} {$operator} ?";
                    }
                    break;

                case 'in':
                    $placeholders = rtrim(str_repeat('?, ', count($where['values'])), ', ');
                    $sql = "{$where['column']} IN ({$placeholders})";
                    break;

                case 'notIn':
                    $placeholders = rtrim(str_repeat('?, ', count($where['values'])), ', ');
                    $sql = "{$where['column']} NOT IN ({$placeholders})";
                    break;

                case 'between':
                    $sql = "{$where['column']} BETWEEN ? AND ?";
                    break;

                case 'notBetween':
                    $sql = "{$where['column']} NOT BETWEEN ? AND ?";
                    break;

                case 'null':
                    $sql = "{$where['column']} IS NULL";
                    break;

                case 'notNull':
                    $sql = "{$where['column']} IS NOT NULL";
                    break;

                case 'nested':
                case 'orNested':
                    /** @var QueryBuilder $query */
                    $query = $where['query'];
                    $nestedWhere = $query->compileWheres();
                    $sql = "({$nestedWhere})";
                    break;

                case 'raw':
                    $sql = $where['sql'];
                    break;
            }

            if (!empty($sql)) {
                $wheres[] = $boolean . ' ' . $sql;
            }
        }

        // Ersten Operator entfernen
        if (!empty($wheres) && (str_starts_with($wheres[0], 'AND ') || str_starts_with($wheres[0], 'OR '))) {
            $wheres[0] = substr($wheres[0], 4);
        }

        return implode(' ', $wheres);
    }

    /**
     * Kompiliert HAVING-Klauseln
     *
     * @return string SQL für HAVING-Klauseln
     */
    private function compileHavings(): string
    {
        if (empty($this->havings)) {
            return '';
        }

        $havings = [];

        foreach ($this->havings as $i => $having) {
            $type = $having['type'];
            $boolean = $i > 0 ? 'AND ' : '';

            if ($type === 'basic') {
                $havings[] = $boolean . "{$having['column']} {$having['operator']} ?";
            }
        }

        return implode(' ', $havings);
    }

    /**
     * Kompiliert eine INSERT-Abfrage
     *
     * @param array $values Einzufügende Werte
     * @return string SQL-Abfrage
     */
    private function compileInsert(array $values): string
    {
        $columns = implode(', ', array_map(fn($col) => "`{$col}`", array_keys($values)));
        $parameters = implode(', ', array_map(fn($col) => ":{$col}", array_keys($values)));

        return "INSERT INTO {$this->table} ({$columns}) VALUES ({$parameters})";
    }

    /**
     * Kompiliert eine INSERT-Abfrage für mehrere Datensätze
     *
     * @param array $columns Spaltennamen
     * @param int $rowCount Anzahl der Zeilen
     * @return string SQL-Abfrage
     */
    private function compileInsertMany(array $columns, int $rowCount): string
    {
        $columnsList = implode(', ', array_map(fn($col) => "`{$col}`", $columns));

        $valuesSets = [];
        $paramIndex = 0;

        for ($i = 0; $i < $rowCount; $i++) {
            $params = [];

            foreach ($columns as $column) {
                $params[] = '?';
                $paramIndex++;
            }

            $valuesSets[] = '(' . implode(', ', $params) . ')';
        }

        $valuesList = implode(', ', $valuesSets);

        return "INSERT INTO {$this->table} ({$columnsList}) VALUES {$valuesList}";
    }

    /**
     * Kompiliert eine UPDATE-Abfrage
     *
     * @param array $values Zu aktualisierende Werte
     * @return string SQL-Abfrage
     */
    private function compileUpdate(array $values): string
    {
        $sets = [];

        foreach (array_keys($values) as $column) {
            $sets[] = "`{$column}` = :{$column}";
        }

        $setSql = implode(', ', $sets);
        $whereSql = $this->compileWheres();

        $sql = "UPDATE {$this->table} SET {$setSql}";

        if (!empty($whereSql)) {
            $sql .= " WHERE {$whereSql}";
        }

        return $sql;
    }

    /**
     * Kompiliert eine Raw-UPDATE-Abfrage
     *
     * @param string $expression SET-Ausdruck
     * @return string SQL-Abfrage
     */
    private function compileUpdateRaw(string $expression): string
    {
        $whereSql = $this->compileWheres();

        $sql = "UPDATE {$this->table} SET {$expression}";

        if (!empty($whereSql)) {
            $sql .= " WHERE {$whereSql}";
        }

        return $sql;
    }

    /**
     * Kompiliert eine DELETE-Abfrage
     *
     * @return string SQL-Abfrage
     */
    private function compileDelete(): string
    {
        $whereSql = $this->compileWheres();

        $sql = "DELETE FROM {$this->table}";

        if (!empty($whereSql)) {
            $sql .= " WHERE {$whereSql}";
        }

        return $sql;
    }

    /**
     * Invalidiert alle Cache-Einträge für die aktuelle Tabelle
     *
     * @return void
     */
    private function invalidateTableCache(): void
    {
        if ($this->cache instanceof NullCache) {
            return;
        }

        $this->logger->debug("Invalidiere Cache für Tabelle", [
            'connection' => $this->connectionName,
            'table' => $this->table
        ]);

        $this->cache->invalidateByTag("table:{$this->connectionName}:{$this->table}");
    }

    /**
     * Gibt die verwendete Cache-Instanz zurück
     *
     * @return CacheInterface Cache-Instanz
     */
    public function getCache(): CacheInterface
    {
        return $this->cache;
    }

    /**
     * Aktiviert das Cache-Tagging für diese Abfrage
     *
     * Tags ermöglichen eine genauere Invalidierung von zusammengehörigen Caches
     *
     * @param string|null $key Optionaler Cache-Key (wird automatisch generiert, wenn nicht angegeben)
     * @param int $ttl Cache-Lebensdauer in Sekunden (null für unbegrenzt)
     * @param array $tags Array von Tags zur Kategorisierung des Caches
     * @return self
     */
    public function cacheWithTags(?string $key = null, int $ttl = 3600, array $tags = []): self
    {
        // Tabellennamen als Standard-Tag verwenden, wenn keine Tags angegeben
        if (empty($tags) && !empty($this->table)) {
            // Aliasse vom Tabellennamen entfernen (z.B. "users AS u" -> "users")
            $tableName = preg_replace('/\s+AS\s+.*/i', '', $this->table);
            $tags = [$tableName];
        }

        $this->cacheTtl = $ttl;
        $this->cacheKey = $key;
        $this->cacheTags = $tags;

        return $this;
    }

    /**
     * Invalidiert alle Caches, die mit einem bestimmten Tag versehen sind
     *
     * @param string $tag Tag, der invalidiert werden soll
     * @return bool True bei Erfolg
     */
    public function invalidateCacheTag(string $tag): bool
    {
        return $this->cache->invalidateByTag($tag);
    }

    /**
     * Führt eine Cache-Operation aus und fängt Ausnahmen ab
     *
     * @param string $operation Name der Operation ('set', 'get', usw.)
     * @param callable $callback Auszuführende Operation
     * @param mixed $default Standardwert, falls die Operation fehlschlägt
     * @return mixed Ergebnis der Operation oder Standardwert
     */
    private function safeCacheOperation(string $operation, callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            $this->logger->error("Fehler bei Cache-Operation '$operation': " . $e->getMessage(), [
                'connection' => $this->connectionName,
                'table' => $this->table,
                'exception' => get_class($e)
            ]);

            return $default;
        }
    }
}