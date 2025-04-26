# PHP 8.4 PDO Query Builder

Ein moderner, typsicherer PDO Query Builder für PHP 8.4 mit vielen fortschrittlichen Funktionen.

## Hauptmerkmale

- **ConnectionManager**: Unterstützung für mehrere Datenbankverbindungen (z.B. forum, pages, game)
- **Nur MySQL**: Optimiert für MySQL-Datenbanken
- **Lazy-Initialisierung**: Verbindungen werden erst bei Bedarf hergestellt
- **Fluent Interface**: Verkettete Methodenaufrufe für eine intuitive API
- **Starke Typen**: Vollständig typisiert mit PHP 8.4 Typsystem und Rückgabetypen
- **Loadbalancing**: Unterstützung für mehrere Datenbankserver mit verschiedenen Strategien
- **Enums**: Verwendung von PHP 8.1 Enums für bessere Typsicherheit und Konsistenz
- **Chaining ohne Klammern**: Verkettete Methodenaufrufe ohne Verschachtelung
- **Transaction Support**: Umfassende Unterstützung für Transaktionen via Trait
- **Pagination**: Flexible Paginierungsoptionen (Standard, Cursor-basiert, einfach)
- **Typed Promises**: Implementierung des Future-Patterns für asynchrone Aufrufe
- **SQL-Injection-Schutz**: Prepared Statements für alle Abfragen
- **Caching**: Integrierte Unterstützung für Abfrage-Caching

## Installationsanforderungen

- PHP 8.4 oder höher
- PDO-MySQL-Erweiterung
- Composer für die Abhängigkeitsverwaltung

## Verzeichnisstruktur

```
src/
  ├── Database/
  │   ├── Cache/             # Cache-Implementierungen
  │   ├── Enums/             # Enum-Definitionen
  │   ├── Exceptions/        # Spezifische Ausnahmen
  │   ├── Traits/            # Traits für QueryBuilder
  │   ├── ConnectionConfig.php
  │   ├── ConnectionManager.php
  │   ├── DatabaseFactory.php
  │   ├── Future.php
  │   ├── LoadBalancingStrategy.php
  │   ├── PaginationResult.php
  │   ├── QueryBuilder.php
  │   ├── Server.php
  │   └── ...
  └── Log/                   # Logger-Implementierungen
examples/
  └── query-builder-usage.php  # Beispiel zur Verwendung
```

## Grundlegende Verwendung

### Verbindungskonfiguration

```php
use Src\Database\DatabaseFactory;
use Src\Database\Enums\ConnectionMode;
use Src\Database\LoadBalancingStrategy;

// Verbindung konfigurieren
DatabaseFactory::configureConnection(
    name: 'forum',
    database: 'forum_db',
    servers: [
        [
            'name' => 'master',
            'host' => 'db-master.example.com',
            'username' => 'dbuser',
            'password' => 'password123',
            'type' => 'primary'
        ],
        [
            'name' => 'replica1',
            'host' => 'db-replica1.example.com',
            'username' => 'readonly',
            'password' => 'password123',
            'type' => 'read'
        ]
    ],
    loadBalancingStrategy: LoadBalancingStrategy::ROUND_ROBIN,
    defaultMode: ConnectionMode::READ
);
```

### SELECT-Abfragen

```php
// QueryBuilder erstellen
$query = DatabaseFactory::createQueryBuilder('forum', 'users');

// Einfache Abfrage
$users = $query->select(['id', 'username', 'email'])
    ->where('is_active', true)
    ->orderBy('username')
    ->limit(10)
    ->get();

// Mit JOIN
$posts = DatabaseFactory::createQueryBuilder('forum')
    ->table('posts')
    ->select(['posts.id', 'posts.title', 'users.username'])
    ->leftJoin('users', 'posts.user_id', '=', 'users.id')
    ->where('posts.status', 'published')
    ->get();
```

### INSERT, UPDATE, DELETE

```php
// INSERT
$newId = DatabaseFactory::createQueryBuilder('forum')
    ->table('posts')
    ->insert([
        'title' => 'Neuer Beitrag',
        'content' => 'Inhalt des Beitrags',
        'user_id' => 42,
        'created_at' => date('Y-m-d H:i:s')
    ]);

// UPDATE
$affected = DatabaseFactory::createQueryBuilder('forum')
    ->table('posts')
    ->where('id', $newId)
    ->update([
        'title' => 'Aktualisierter Titel',
        'updated_at' => date('Y-m-d H:i:s')
    ]);

// DELETE
$deleted = DatabaseFactory::createQueryBuilder('forum')
    ->table('posts')
    ->where('id', $newId)
    ->delete();
```

### Transaktionen

```php
$db = DatabaseFactory::createQueryBuilder('forum');

try {
    $db->beginTransaction();
    
    $threadId = $db->table('threads')
        ->insert([
            'title' => 'Neuer Thread',
            'user_id' => 42
        ]);
    
    $postId = $db->table('posts')
        ->insert([
            'thread_id' => $threadId,
            'content' => 'Erster Beitrag im Thread',
            'user_id' => 42
        ]);
    
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
    throw $e;
}
```

### Paginierung

```php
// Standard-Paginierung
$page = 1;
$perPage = 20;

$result = DatabaseFactory::createQueryBuilder('forum')
    ->table('posts')
    ->where('status', 'published')
    ->orderBy('created_at', 'DESC')
    ->paginate($page, $perPage);

echo "Seite {$result->currentPage} von {$result->lastPage}, " .
     "zeige {$result->from}-{$result->to} von {$result->total} Ergebnissen";

// Cursor-Paginierung
$cursorResult = DatabaseFactory::createQueryBuilder('forum')
    ->table('posts')
    ->where('status', 'published')
    ->orderBy('id')
    ->cursorPaginate('id', $_GET['cursor'] ?? null, 20);
```

### Asynchrone Abfragen mit Future

```php
// Future erstellen
$usersFuture = DatabaseFactory::createQueryBuilder('forum')
    ->table('users')
    ->where('is_active', true)
    ->async();

$threadsFuture = DatabaseFactory::createQueryBuilder('forum')
    ->table('threads')
    ->where('is_sticky', true)
    ->async();

// Andere Operationen ausführen...

// Ergebnisse abrufen, wenn sie benötigt werden
$users = $usersFuture->get();
$threads = $threadsFuture->get();
```

### Caching

```php
// Cache-Provider registrieren
$cache = DatabaseFactory::createArrayCache('default');

// Abfrage mit Caching
$posts = DatabaseFactory::createQueryBuilder('forum', 'posts', null, $cache)
    ->where('is_active', true)
    ->orderBy('created_at', 'DESC')
    ->cache('active_posts', 3600) // 1 Stunde cachen
    ->get();
```

## Fortgeschrittene Funktionen

### Verschachtelte WHERE-Bedingungen

```php
$posts = DatabaseFactory::createQueryBuilder('forum')
    ->table('posts')
    ->whereNested(function($query) {
        $query->where('title', 'LIKE', '%PHP%')
              ->orWhere('content', 'LIKE', '%PHP%');
    })
    ->whereIn('category_id', [1, 2, 3])
    ->whereBetween('created_at', '2023-01-01', '2023-12-31')
    ->get();
```

### Aggregationen

```php
$stats = DatabaseFactory::createQueryBuilder('forum')
    ->table('posts')
    ->select(['category_id', 'COUNT(*) as post_count', 'AVG(view_count) as avg_views'])
    ->groupBy('category_id')
    ->having('post_count', '>', 10)
    ->get();
```

### Chunk-Verarbeitung für große Datensätze

```php
DatabaseFactory::createQueryBuilder('forum')
    ->table('posts')
    ->orderBy('id')
    ->chunk(100, function($posts) {
        foreach ($posts as $post) {
            // Jede Post-Gruppe verarbeiten
        }
    });
```

## Beitragen

Beiträge sind willkommen! Bitte stellen Sie sicher, dass Ihre Änderungen:

1. Die PHP 8.4 Typsicherheit beibehalten
2. Die bestehenden Tests durchlaufen
3. Bei Bedarf neue Tests enthalten
4. Die Codierungsstandards einhalten

## Lizenz

Dieses Projekt steht unter der MIT-Lizenz.