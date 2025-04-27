# Datenanonymisierung für PHP 8.4 PDO Query Builder

Eine leistungsstarke Erweiterung für den PHP 8.4 PDO Query Builder, die automatische Anonymisierung sensibler Daten ermöglicht, um den Datenschutz zu verbessern und die DSGVO-Konformität zu unterstützen.

## Funktionen

- **Automatische Anonymisierung**: Anonymisieren Sie sensible Daten direkt in Datenbankabfragen
- **Mehrere Anonymisierungsstrategien**: Vordefinierte Strategien für E-Mail, Namen, Telefonnummern, Adressen, IP-Adressen und mehr
- **Anpassbare Optionen**: Konfigurieren Sie jede Anonymisierungsstrategie nach Ihren Bedürfnissen
- **Attribut-basierte Anonymisierung**: Verwenden Sie PHP-Attribute, um Felder in Ihren Modellklassen zu kennzeichnen
- **Middleware-Unterstützung**: Automatische API-Antwort-Anonymisierung
- **DSGVO-Export-Unterstützung**: Erstellen Sie anonymisierte Datenexporte für DSGVO-Anfragen
- **Benutzerrollenbasierte Anonymisierung**: Passen Sie die Anonymisierungsstufe je nach Benutzerrolle an
- **Erweiterbar**: Einfach um benutzerdefinierte Anonymisierungsstrategien erweiterbar

## Installation

1. Dateien in Ihr Projekt kopieren:
   ```bash
   # Erstellen Sie die erforderlichen Verzeichnisse
   mkdir -p src/Database/Anonymization
   
   # Kopieren Sie die Dateien
   cp AnonymizationService.php src/Database/Anonymization/
   cp Anonymizable.php src/Database/Anonymization/
   cp DataAnonymizerTrait.php src/Database/Traits/
   cp QueryBuilderAnonymizationTrait.php src/Database/Traits/
   cp AnonymizationMiddleware.php src/Http/Middleware/
   cp GdprExportService.php src/Services/
   ```

2. Trait in den QueryBuilder integrieren (siehe `QueryBuilder-Ergänzungen.php`):
    - Fügen Sie `use QueryBuilderAnonymizationTrait;` zum QueryBuilder hinzu
    - Modifizieren Sie die `get()` und `first()`-Methoden, um Anonymisierung zu unterstützen

## Verwendung

### Einfache Anonymisierung in einer Abfrage

```php
$users = DatabaseFactory::createQueryBuilder('main', 'users')
    ->select(['id', 'username', 'email', 'full_name', 'phone', 'address'])
    ->where('is_active', true)
    ->anonymize([
        'email' => 'email',
        'full_name' => 'name',
        'phone' => 'phone',
        'address' => 'address'
    ])
    ->get();
```

### Anonymisierung mit benutzerdefinierten Optionen

```php
$users = DatabaseFactory::createQueryBuilder('main', 'users')
    ->select(['id', 'username', 'email', 'phone'])
    ->anonymize([
        'email' => [
            'strategy' => 'email',
            'options' => ['preserve_domain' => false]
        ],
        'phone' => [
            'strategy' => 'phone',
            'options' => ['visible_digits' => 2]
        ]
    ])
    ->get();
```

### Modellbasierte Anonymisierung mit Attributen

```php
class User
{
    use DataAnonymizerTrait;
    
    public int $id;
    public string $username;
    
    #[Anonymizable(strategy: 'email')]
    public string $email;
    
    #[Anonymizable(strategy: 'name')]
    public string $fullName;
    
    #[Anonymizable(strategy: 'phone')]
    public string $phoneNumber;
    
    // ...
    
    public function toArray(): array
    {
        // Implementierung...
    }
}

// Verwendung:
$user = new User(/* ... */);
$anonymizedData = $user->toAnonymizedArray();
```

### API-Anonymisierung mit Middleware

```php
$middleware = new AnonymizationMiddleware(
    new AnonymizationService($logger),
    $logger,
    [
        'email' => 'email',
        'full_name' => 'name',
        'phone' => 'phone',
        'address' => 'address'
    ]
);

// Registrieren der Middleware im Router
$router->addMiddleware($middleware);
```

### DSGVO-Datenexport mit Anonymisierung

```php
$gdprService = new GdprExportService(
    new AnonymizationService($logger),
    $logger
);

$exportData = $gdprService->createUserDataExport(
    userId: $userId,
    dataSources: $dataSources,
    anonymizeSensitiveData: true
);

$filePath = $gdprService->saveExportToJson(
    exportData: $exportData,
    directory: '/path/to/exports'
);
```

## Verfügbare Anonymisierungsstrategien

| Strategie | Beschreibung | Optionen |
|-----------|--------------|----------|
| `email` | Anonymisiert E-Mail-Adressen | `preserve_domain`: Behalte Domain bei (Standard: true) |
| `name` | Anonymisiert Namen | `preserve_first_char`: Behalte ersten Buchstaben (Standard: true)<br>`placeholder`: Zu verwendendes Platzhalterzeichen (Standard: ****) |
| `phone` | Anonymisiert Telefonnummern | `visible_digits`: Anzahl der sichtbaren Ziffern am Ende (Standard: 3) |
| `address` | Anonymisiert Adressen | `preserve_postal_code`: Behalte Postleitzahl bei (Standard: true) |
| `ip` | Anonymisiert IP-Adressen | `method`: Anonymisierungsmethode ('partial' oder 'full') (Standard: partial) |
| `credit_card` | Anonymisiert Kreditkartennummern | `visible_digits`: Anzahl der sichtbaren Ziffern am Ende (Standard: 4) |
| `hash` | Erstellt Hash-Werte (deterministisch) | `algorithm`: Zu verwendender Hash-Algorithmus (Standard: xxh3)<br>`salt`: Salt für den Hash (Standard: '')<br>`length`: Maximale Länge des Hashes (Standard: 0 = unbegrenzt) |
| `random` | Erzeugt zufällige Werte | `length`: Länge des zufälligen Strings (Standard: 10)<br>`characters`: Zu verwendende Zeichen |
| `null` | Ersetzt Werte durch NULL | - |

## Eigene Anonymisierungsstrategien registrieren

```php
$anonymizer = new AnonymizationService($logger);

$anonymizer->registerStrategy('coordinates', function(string $value, array $options = []): string {
    $precision = $options['precision'] ?? 2;
    
    if (!preg_match('/^(-?\d+\.\d+),\s*(-?\d+\.\d+)$/', $value, $matches)) {
        return $value;
    }
    
    $lat = round((float)$matches[1], $precision);
    $lon = round((float)$matches[2], $precision);
    
    return "$lat, $lon";
});
```

## Bewährte Praktiken

1. **Speichern Sie immer die Originaldaten**: Die Anonymisierung sollte nur für die Anzeige/Export angewendet werden, nicht für die Speicherung.
2. **Schichten Sie die Anonymisierung**: Verwenden Sie unterschiedliche Anonymisierungsstufen für verschiedene Benutzerrollen.
3. **Verwenden Sie Attribut-basierte Anonymisierung** für Modelle, um konsistentes Verhalten über das gesamte System zu gewährleisten.
4. **Cachen Sie anonymisierte Daten nicht**, wenn die Anonymisierungsstufe vom Benutzerkontext abhängt.
5. **Loggen Sie Anonymisierungsaktivitäten**, um Datenschutzprüfungen zu unterstützen.

## Leistungsüberlegungen

- Die Anonymisierung fügt einen gewissen Overhead hinzu, besonders bei großen Ergebnismengen.
- Verwenden Sie für sehr große Datenmengen Batch-Verarbeitung (`chunk()`-Methode).
- Anonymisierungslogik wird zur Laufzeit angewendet, nicht in der Datenbank.

## Tests

Umfangreiche Tests wurden implementiert, um die korrekte Funktionalität der Anonymisierungskomponente sicherzustellen:

- Einheitstests für jede Anonymisierungsstrategie
- Integrationstests für den QueryBuilder mit Anonymisierung
- Funktionstests für Middleware und Export-Service

Die Tests können ausgeführt werden mit:

```bash
composer test
```

## Lizenz

MIT-Lizenz