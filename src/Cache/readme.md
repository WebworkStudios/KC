# Caching-System für PHP 8.4 ADR Framework

Das Caching-System bietet eine leistungsstarke und flexible Lösung zum Speichern und Wiederherstellen von Daten in Ihrer
Anwendung. Es basiert auf dem PSR-16 Simple Cache-Interface und unterstützt verschiedene Backends wie Datei-Caching und
Redis.

## Hauptmerkmale

- **PSR-16 kompatibel**: Implementiert das Simple Cache Interface mit einer einheitlichen API
- **Mehrere Cache-Backends**: Unterstützung für File-Cache, Redis-Cache und Null-Cache
- **Flexible Konfiguration**: Anpassbare Optionen wie TTL, Präfix und Backend-spezifische Einstellungen
- **HTTP-Caching**: Middleware für das Caching von HTTP-Antworten
- **Performance-Optimiert**: Effiziente Implementierung mit speziellen Optimierungen für jedes Backend
- **Typ-Sicherheit**: Vollständige Unterstützung von PHP 8.4 Typen
- **Logging-Integration**: Umfangreiche Logging-Unterstützung für Debugging und Monitoring

## Grundlegende Verwendung

### Cache-Erstellung über Factory

```php
// Cache-Factory erstellen
$cacheFactory = new Src\Cache\CacheFactory($logger);

// Standard-Cache für Umgebung erstellen
$cache = $cacheFactory->createDefaultCache('production', [
    'prefix' => 'myapp',
    'redis' => [
        'host' => 'redis.example.com',
        'port' => 6379,
        'password' => 'secret',
        'database' => 1
    ],
    'file' => [
        'dir' => '/path/to/cache',
        'permissions' => [
            'directory' => 0775,
            'file' => 0664
        ]
    ]
]);

// Spezifischen Cache erstellen
$fileCache = $cacheFactory->createCache('file', [
    'prefix' => 'file_cache',
    'file' => [
        'dir' => '/path/to/cache'
    ]
]);
```

### Direktes Caching

```php
// Wert in Cache setzen mit TTL (time-to-live) in Sekunden
$cache->set('user:123', $userData, 3600); // 1 Stunde

// Wert aus Cache holen mit Standard-Fallback
$userData = $cache->get('user:123', ['name' => 'Guest']);

// Prüfen, ob ein Schlüssel im Cache existiert
if ($cache->has('user:123')) {
    // Wert aus Cache löschen
    $cache->delete('user:123');
}

// Mehrere Werte auf einmal setzen
$cache->setMultiple([
    'user:123' => $userData,
    'settings:123' => $settings
], 3600);

// Mehrere Werte auf einmal lesen
$values = $cache->getMultiple(['user:123', 'settings:123'], $defaultValue);

// Cache komplett leeren
$cache->clear();
```

### Verwendung mit DI-Container

```php
// Mit DI-Container registrieren
$container->bind(Src\Cache\CacheInterface::class, Src\Cache\FileCache::class);
// oder vorkonfigurierte Instanz registrieren
$container->register(Src\Cache\CacheInterface::class, $cache);

// In einer Klasse per Konstruktor-Injektion
class UserService {
    public function __construct(
        private readonly Src\Cache\CacheInterface $cache
    ) {
    }
    
    public function getUserById(int $id): array {
        $cacheKey = "user:{$id}";
        
        // Aus Cache laden, falls vorhanden
        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }
        
        // Aus Datenbank laden
        $userData = $this->fetchFromDatabase($id);
        
        // Im Cache speichern (1 Stunde gültig)
        $this->cache->set($cacheKey, $userData, 3600);
        
        return $userData;
    }
}
```

## Verfügbare Cache-Implementierungen

### FileCache

Speichert Cache-Einträge als Dateien im Dateisystem.

```php
$fileCache = new Src\Cache\FileCache(
    '/path/to/cache',         // Cache-Verzeichnis
    'myapp',                  // Optionales Präfix
    $logger,                  // Optional: Logger-Instanz
    0775,                     // Verzeichnis-Berechtigungen
    0664,                     // Datei-Berechtigungen
    true                      // Tiefe Verzeichnisstr