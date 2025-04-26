# Logger System für PHP 8.4 ADR Framework

Das Logger-System bietet eine leistungsstarke und flexible Lösung zum Protokollieren von Nachrichten und Ereignissen in Ihrer Anwendung. Es basiert auf dem Interface-Design-Pattern und ist vollständig typensicher mit PHP 8.4.

## Hauptmerkmale

- **Interface-basiertes Design**: Klare Trennung zwischen Interface und Implementierung
- **Verschiedene Logger-Implementierungen**: FileLogger, ConsoleLogger, SyslogLogger, und mehr
- **Kontextbasiertes Logging**: Unterstützung für strukturierte Logs mit Kontext-Daten
- **Prozessor-System**: Erweiterung von Logs mit zusätzlichen Informationen
- **Flexibles Log-Routing**: Weiterleitung von Logs an mehrere Ziele gleichzeitig
- **Vollständige PSR-3 Kompatibilität**: Folgt den Standard-Log-Levels und -Methoden
- **Keine externen Abhängigkeiten**: Eigenständige Implementierung ohne Drittanbieter-Bibliotheken
- **Leistungsoptimiert**: Effiziente Implementierung mit minimalen Overhead

## Grundlegende Verwendung

### Logger-Erstellung über Factory

```php
// Logger-Factory erstellen
$loggerFactory = new Src\Log\LoggerFactory('/path/to/logs', 'debug');

// Standard-Logger für Umgebung erstellen
$logger = $loggerFactory->createDefaultLogger('development');

// Spezifischen Logger erstellen
$fileLogger = $loggerFactory->createLogger('file', [
    'filename' => 'custom.log',
    'level' => 'info'
]);
```

### Direktes Logging

```php
// Log-Nachricht mit Kontext
$logger->info('Benutzer hat sich angemeldet', [
    'user_id' => 123,
    'username' => 'johndoe',
    'ip' => '192.168.1.1'
]);

// Platzhalter in Nachrichten
$logger->error('Fehler bei Benutzer {username}: {message}', [
    'username' => 'johndoe',
    'message' => 'Passwort ungültig'
]);
```

### Verwendung mit Container

```php
// Mit DI-Container
$container->register(Src\Log\LoggerInterface::class, $logger);

// In einer Klasse per Konstruktor-Injektion
class UserService {
    public function __construct(
        private readonly Src\Log\LoggerInterface $logger
    ) {
    }
    
    public function login(string $username, string $password): bool {
        $this->logger->info('Login-Versuch', [
            'username' => $username
        ]);
        
        // ...
    }
}
```

## Verfügbare Logger

### FileLogger

Schreibt Logs in eine Datei mit Zeitstempel und strukturiertem Format.

```php
$fileLogger = new Src\Log\FileLogger(
    '/path/to/logs/app.log',  // Dateipfad
    'debug',                  // Minimales Log-Level
    'a'                       // Dateimodus (a = append, w = überschreiben)
);
```
### SyslogLogger

Schreibt Logs in das Systemlog (syslog) mit entsprechenden Prioritäten.

```php
$syslogLogger = new Src\Log\SyslogLogger(
    'php-app',      // Identifikation für Syslog-Einträge
    LOG_USER,       // Syslog-Facility
    'notice'        // Minimales Log-Level
);
```

### AggregateLogger

Leitet Logs an mehrere Logger gleichzeitig weiter.

```php
$aggregateLogger = new Src\Log\AggregateLogger([
    $fileLogger,
    $consoleLogger
]);

// Weitere Logger hinzufügen
$aggregateLogger->addLogger($syslogLogger);
```

### DebugLogger

Erweitert Logs um Informationen über die Aufrufstelle (Datei, Zeile, Klasse, Methode).

```php
$debugLogger = new Src\Log\DebugLogger(
    $fileLogger,    // Ziel-Logger
    'debug'         // Minimales Log-Level
);
```

### ProcessorLogger

Unterstützt Prozessoren, die Logs vor der Weiterleitung bearbeiten können.

```php
$processorLogger = new Src\Log\ProcessorLogger($fileLogger);

// Prozessor hinzufügen
$processorLogger->addProcessor(function(string $level, string $message, array $context) {
    // Kontext erweitern
    $context['timestamp'] = time();
    $context['memory'] = memory_get_usage(true);
    
    return $context;
});
```

### NullLogger

Verwirft alle Logs, nützlich für Tests oder wenn Logging deaktiviert werden soll.

```php
$nullLogger = new Src\Log\NullLogger();
```

## Log-Prozessoren

Prozessoren können Logs um zusätzliche Informationen erweitern.

```php
use Src\Log\Processor\ContextProcessor;

// Kontext-Prozessor erstellen
$contextProcessor = new ContextProcessor([
    'app' => 'my-app',
    'environment' => 'production'
]);

// Kontext-Prozessor um Benutzerinformationen erweitern
$contextProcessor->addContext('user_id', $userId);

// Prozessor zum Logger hinzufügen
$processorLogger->addProcessor($contextProcessor);
```

## HTTP-Logging mit Middleware

Die `LoggingMiddleware` protokolliert automatisch alle HTTP-Anfragen.

```php
// LoggingMiddleware erstellen
$loggingMiddleware = new Src\Http\Middleware\LoggingMiddleware($logger);

// In Router einbinden oder direkt verwenden
$response = $loggingMiddleware->process($request, function($request) {
    // Anfrage verarbeiten
    return new Response('OK');
});
```

## Tipps für optimales Logging

1. **Strukturiertes Logging verwenden**: Immer Kontext-Daten übergeben statt alles in die Nachricht zu packen
2. **Sinnvolle Log-Levels wählen**: Wichtige Fehler als error/critical, Warnungen als warning, Informationen als info, Details als debug
3. **Platzhalter nutzen**: Mit `{key}` in der Nachricht und entsprechenden Werten im Kontext
4. **Performance beachten**: Teure Berechnungen für Logs nur ausführen, wenn der entsprechende Log-Level aktiv ist
5. **In Produktionsumgebung**: Log-Level auf info oder höher setzen, um Performance zu optimieren

## Erweiterung

Das Logger-System kann leicht erweitert werden:

1. **Eigene Logger erstellen**: Von `AbstractLogger` ableiten und `doLog()` implementieren
2. **Eigene Prozessoren erstellen**: Callable erstellen, das (level, message, context) => context verarbeitet
3. **In LoggerFactory registrieren**: Mit `registerLoggerType()` eigene Logger-Typen verfügbar machen