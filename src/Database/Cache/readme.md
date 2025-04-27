# Redis Database Cache für PHP 8.4 ADR Framework

Diese Erweiterung bietet eine leistungsstarke Redis-basierte Cache-Implementierung für das Datenbankschicht des PHP 8.4
ADR Frameworks. Sie ermöglicht das effiziente Caching von Datenbankabfragen, um die Leistung zu optimieren und die
Datenbanklast zu reduzieren.

## Hauptmerkmale

- **Redis-Integration**: Schnelle, In-Memory-Caching-Lösung mit persistenter Speicherung
- **Tag-basierte Invalidierung**: Selektives Invalidieren von zusammengehörigen Cache-Einträgen
- **Automatisches Prefixing**: Verhindert Schlüsselkollisionen mit anderen Anwendungen
- **TTL-Unterstützung**: Automatisches Ablaufen von Cache-Einträgen
- **Transaktionssichere Invalidierung**: Koordinierte Cache-Aktualisierung bei Datenbankänderungen
- **Einfache Integration**: Nahtlose Einbindung in das bestehende QueryBuilder-System

## Installation

Stellen Sie sicher, dass die PHP Redis-Erweiterung installiert ist:

```bash
# Für Ubuntu/Debian
sudo apt-get install php-redis

# Für CentOS/RHEL
sudo yum install php-redis

# Für macOS mit Homebrew
brew install php@8.4
brew install redis
pecl install redis
```

Fügen Sie die Redis-Cache-Dateien zu Ihrem Projekt hinzu und stellen Sie sicher, dass sie vom Autoloader geladen werden.

## Konfiguration

### In `app/bootstrap.php` oder `app/cache-bootstrap.php`:

```php
// Redis-Cache für die Datenbank initialisieren
use Src\Database\DatabaseFactoryRedisExtension;
use Src\Config;

function bootstrapDatabaseCache(Container $container): void
{
    // Logger holen
    $logger = $container->get(LoggerInterface::class);
    
    // Cache-Konfiguration
    $config = $container->get(Config::class);
    $redisConfig = $config->get('cache.backends.redis', [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 1,
        'password' => null,
        'prefix' => 'db_cache:'
    ]);
    
    try {
        // Redis-Cache erstellen und registrieren
        $cache = DatabaseFactoryRedisExtension::createRedisCache(
            name: 'db_cache',
            config: $redisConfig,
            logger: $logger
        );
        
        $logger->info("Redis Database Cache initialisiert", [
            'host' => $redisConfig['host'],
            'port' => $redisConfig['port']
        ]);
    } catch (\Throwable $e) {
        $logger->error("Fehler beim Initialisieren des Redis-Cache: " . $e->getMessage(), [
            'exception' => get_class($e)
        ]);
    }
}
```

### In `app/Config/cache.php`:

```php
return [
    // Standard-Cache-Typ
    'default' => env('CACHE_DRIVER', 'file'),

    // Präfix für alle Cache-Schlüssel
    'prefix' => env('CACHE_PREFIX', 'app'),

    // TTL in Sekunden (Standard: 1 Stunde)
    'ttl' => env('CACHE_TTL', 3600),

    // Verfügbare Cache-Backends
    'backends' => [
        // ... bestehende Konfiguration ...
        
        'redis' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'timeout' => env('REDIS_TIMEOUT', 0.0),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_CACHE_DB', 1),
            'persistent' => env('REDIS_PERSISTENT', true),
            'prefix' => env('REDIS_PREFIX', 'db_cache:'),
        ],
    ],
    
    // Einstellungen für das Datenbank-Caching
    'database' => [
        'enabled' => env('CACHE_DATABASE_ENABLED', true),
        'driver' => env('CACHE_DATABASE_DRIVER', 'redis'),
        'ttl' => env('CACHE_DATABASE_TTL', 3600),
        'auto_invalidate' => env('CACHE_DATABASE_AUTO_INVALIDATE', true),
    ],
];
```

## Grundlegende Verwendung

### QueryBuilder mit Redis-Cache erstellen

```php
// Direkt einen QueryBuilder mit Redis-Cache erstellen
$query = DatabaseFactoryRedisExtension::createQueryBuilderWithRedisCache(
    connectionName: 'main',
    redisConfig: $redisConfig,
    table: 'users',
    logger: $logger
);

// Oder einen vorhandenen QueryBuilder verwenden und den Cache aktivieren
$query = (new QueryBuilder($connectionManager, 'main', $logger, $redisCache))
    ->table('users');
```

### Abfragen cachen

```php
// Einfaches Caching mit automatisch generiertem Schlüssel
$users = $query->table('users')
    ->where('status', 'active')
    ->orderBy('created_at', OrderDirection::DESC)
    ->cache() // Verwendet Standardeinstellungen: 1 Stunde TTL
    ->get();

// Mit benutzerdefiniertem Schlüssel und TTL
$recentUsers = $query->table('users')
    ->where('created_at', '>=', date('Y-m-d', strtotime('-7 days')))
    ->cache('recent_users', 1800) // 30 Minuten cachen
    ->get();
    
// Mit Tags für selektive Invalidierung
$products = $query->table('products')
    ->where('category_id', 5)
    ->orderBy('price')
    ->cacheWithTags('category_5_products', 3600, ['products', 'category:5'])
    ->get();
```

### Cache invalidieren

```php
// Alle Caches für eine bestimmte Tabelle invalidieren
$query->table('users')->invalidateTableCache();

// Invalidierung mit einem bestimmten Tag
$cache = $query->getCache();
$cache->invalidateByTag('products');

// Spezifische Tags invalidieren
$cache->invalidateByTag('category:5');
```

### Transaktionen mit Cache-Invalidierung

```php
$query->beginTransaction();

try {
    // Datenbankoperationen durchführen
    $query->table('products')->where('id', 123)->update(['stock' => 10]);
    $query->table('inventory_logs')->insert(['product_id' => 123, 'change' => -1]);
    
    // Transaktion abschließen
    $query->commit();
    
    // Relevante Caches invalidieren
    $cache = $query->getCache();
    $cache->invalidateByTag('products');
    $cache->invalidateByTag('inventory');
} catch (\Throwable $e) {
    // Bei Fehler: Transaktion zurückrollen
    $query->rollback();
    
    // Keine Cache-Invalidierung bei Rollback
    $logger->error("Fehler bei Produktaktualisierung", [
        'product_id' => 123,
        'error' => $e->getMessage()
    ]);
}
```

## Best Practices

### 1. Sinnvolle Cache-Schlüssel und TTLs

- Verwenden Sie aussagekräftige Schlüsselnamen, die den Inhalt beschreiben
- Wählen Sie die TTL basierend auf der Aktualisierungshäufigkeit der Daten
- Dynamischere Daten sollten kürzere TTLs haben

```php
// Statische Daten: Länger cachen
$countries = $query->table('countries')->cache('countries_list', 86400)->get(); // 1 Tag

// Dynamischere Daten: Kürzer cachen
$activeUsers = $query->table('users')
    ->where('status', 'active')
    ->cache('active_users', 300) // 5 Minuten
    ->count();
```

### 2. Effektives Tag-Management

- Verwenden Sie strukturierte Tag-Hierarchien
- Kombinieren Sie allgemeine und spezifische Tags
- Taggen Sie mit Tabellenname, Entitätstyp und spezifischen IDs

```php
// Mehrere Tags für präzise Invalidierung
$userOrders = $query->table('orders')
    ->where('user_id', $userId)
    ->cacheWithTags(
        key: "user_{$userId}_orders",
        ttl: 1800,
        tags: ['orders', "user:{$userId}", 'user_orders']
    )
    ->get();

// Bei Aktualisierung nur die relevanten Tags invalidieren
if ($orderUpdated) {
    $cache->invalidateByTag("user:{$userId}");
}
```

### 3. Cache-Invalidierungsstrategie

- Invalidieren Sie Caches systematisch nach Schreiboperationen
- Verwenden Sie automatische Invalidierung für zusammenhängende Daten
- Implementieren Sie eine einheitliche Strategie im gesamten Projekt

```php
// Bei CREATE/UPDATE/DELETE immer relevante Caches invalidieren
$query->transaction(function ($q) use ($productId, $newData) {
    $updated = $q->table('products')
        ->where('id', $productId)
        ->update($newData);
        
    if ($updated) {
        // Produktbezogene Caches invalidieren
        $q->getCache()->invalidateByTag("product:{$productId}");
        $q->getCache()->invalidateByTag('products');
        
        // Wenn sich die Kategorie geändert hat
        if (isset($newData['category_id'])) {
            $q->getCache()->invalidateByTag("category:{$newData['category_id']}");
        }
    }
    
    return $updated;
});
```

## Performance-Überlegungen

- **Netzwerklatenz**: Stellen Sie sicher, dass Redis auf demselben Server oder in demselben Netzwerksegment läuft
- **Serialisierungsgröße**: Vermeiden Sie das Cachen sehr großer Datensätze
- **TTL-Management**: Verwenden Sie gestaffelte TTLs für verschiedene Datentypen
- **Redis-Persistenz**: Konfigurieren Sie Redis-Persistenz entsprechend Ihren Anforderungen (AOF oder RDB)
- **Verbindungs-Pooling**: Verwenden Sie persistente Verbindungen für hohe Leistung

## Debugging und Monitoring

Der Redis-Cache bietet Methoden zum Monitoring und Debugging:

```php
// Cache-Statistiken abrufen
$stats = $cache->getStats();
echo "Gespeicherte Schlüssel: " . $stats['prefixed_keys'] . "\n";
echo "Speichernutzung: " . $stats['memory_used'] . "\n";

// Verbleibende TTL für einen Schlüssel prüfen
$ttl = $cache->getTtl('user_list');
echo "Verbleibende Gültigkeit: " . ($ttl === -1 ? "Unbegrenzt" : "$ttl Sekunden") . "\n";

// Häufig verwendete Tags anzeigen
echo "Top-Tags:\n";
arsort($stats['tags']);
foreach (array_slice($stats['tags'], 0, 5) as $tag => $count) {
    echo "- $tag: $count Einträge\n";
}
```

Für tiefergehende Analyse können Sie das Redis-CLI oder Redis-Management-Tools verwenden:

```bash
# Alle Schlüssel mit einem bestimmten Präfix anzeigen
redis-cli KEYS "db_cache:*"

# Anzahl der Schlüssel in der Datenbank
redis-cli DBSIZE

# Speicherverbrauch überwachen
redis-cli INFO memory
```