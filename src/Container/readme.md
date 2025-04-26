# Dependency Injection Container für PHP 8.4

Diese Implementierung bietet einen leistungsstarken, modernen Dependency Injection Container mit Service-Registry,
Auto-Wiring und Property Hooks für PHP 8.4.

## Funktionen

- **Service-Registry**: Einfache Verwaltung von Services durch Registrierung von Objekten, Factories oder Klassennamen
- **Auto-Wiring**: Automatische Auflösung von Abhängigkeiten über Konstruktor-Parameter und Property-Injection
- **Interface-Binding**: Verknüpfung von Interfaces mit konkreten Implementierungen
- **Property Hooks**: Automatisches Auslösen von Aktionen bei Änderungen von Objekteigenschaften
- **Typensicherheit**: Vollständige Unterstützung von PHP 8.4 Typen und Attributen
- **Leistungsoptimierung**: Effiziente Implementierung mit Caching von Service-Instanzen

## Installation

Kopieren Sie die Dateien in Ihr Projekt und importieren Sie die benötigten Klassen:

```php
use Advanced\DI\Container;
use Advanced\DI\Inject;
use Advanced\DI\Observable;
use Advanced\DI\PropertyHookAware;
```

## Grundlegende Verwendung

### Container erstellen

```php
$container = new Container();
```

### Services registrieren

```php
// Service über Klassenname registrieren (Lazy-Loading)
$container->register(MyService::class);

// Service-Objekt direkt registrieren
$container->register('config', new Config(['app.name' => 'My App']));

// Service über eine Factory-Funktion registrieren
$container->register('database', function() {
    return new Database('localhost', 'username', 'password');
});
```

### Interface-Binding

```php
// Interface mit konkreter Implementierung verknüpfen
$container->bind(LoggerInterface::class, FileLogger::class);
```

### Service-Parameter setzen

```php
// Parameter für Konstruktor überschreiben
$container->setParameters(FileLogger::class, [
    'logFile' => 'custom.log',
    'level' => 'debug'
]);
```

### Services abrufen

```php
// Service über ID oder Klassenname abrufen
$logger = $container->get(LoggerInterface::class);
$config = $container->get('config');

// Prüfen, ob ein Service existiert
if ($container->has('database')) {
    $db = $container->get('database');
}
```

## Auto-Wiring

Der Container unterstützt zwei Arten von Auto-Wiring:

### Konstruktor-Injection

```php
class UserService {
    // Abhängigkeiten werden automatisch injiziert
    public function __construct(
        private LoggerInterface $logger,
        private Database $database,
        private string $environment = 'production'
    ) {}
}

// UserService mit allen Abhängigkeiten erstellen
$userService = $container->get(UserService::class);
```

### Property-Injection mit Attributen

```php
class ArticleService {
    // Abhängigkeit wird automatisch in die Property injiziert
    #[Inject]
    private LoggerInterface $logger;
    
    // Bestimmte Service-ID angeben
    #[Inject(serviceId: 'custom.repository')]
    private RepositoryInterface $repository;
}
```

## Property Hooks

Property Hooks ermöglichen es, bei Änderungen von Properties automatisch Aktionen auszulösen.

### Observable-Attribut mit spezifischem Callback

```php
class UserManager {
    // Property mit spezifischem Callback-Methode
    #[Observable(callback: 'onUserCountChanged')]
    private int $userCount = 0;
    
    // Wird aufgerufen, wenn sich userCount ändert
    public function onUserCountChanged(int $oldValue, int $newValue): void {
        echo "Benutzeranzahl geändert von $oldValue auf $newValue";
    }
}
```

### PropertyHookAware-Interface für globale Änderungen

```php
class ConfigManager implements PropertyHookAware {
    // Alle Properties mit Observable-Attribut werden überwacht
    #[Observable]
    private string $environment = 'development';
    
    #[Observable]
    private bool $debugMode = false;
    
    // Wird für alle Observable Properties aufgerufen
    public function onPropertyChanged(string $property, mixed $oldValue, mixed $newValue): void {
        echo "Property '$property' wurde geändert von '$oldValue' zu '$newValue'";
    }
}
```

### Properties aktualisieren

```php
// Property ändern und Hooks auslösen
$container->updateProperty($userManager, 'userCount', 10);
$container->updateProperty($configManager, 'environment', 'production');
```

## Leistungsmerkmale

- Schnelle Service-Auflösung durch direkten Zugriff und Caching
- Effiziente Property-Hooks mit WeakMap zur Vermeidung von Speicherlecks
- Optimierte Reflektion-Nutzung für minimalen Overhead
- Automatische Erkennung und Vermeidung von Änderungsbenachrichtigungen für unveränderte Werte

## Abhängigkeiten

- PHP 8.4 oder höher
- Keine externen Bibliotheken erforderlich

## Beispiele

Ausführliche Beispiele finden Sie in den beigefügten Beispieldateien:

- `usage-example.php`: Allgemeine Verwendung des Containers
- `unit-tests.php`: Detaillierte Tests und Anwendungsbeispiele

## Lizenz

MIT-Lizenz