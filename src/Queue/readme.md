# Queue System für PHP 8.4

Diese Implementierung bietet ein leistungsstarkes und flexibles Queue-System für PHP 8.4, das die asynchrone Verarbeitung von Aufgaben, Zeitplanung und Fehlerbehandlung unterstützt.

## Funktionen

- **Asynchrone Verarbeitung**: Verschieben Sie rechenintensive Aufgaben in den Hintergrund, um die Reaktionsfähigkeit Ihrer Anwendung zu verbessern
- **Job-Scheduling**: Planen Sie Jobs für einen bestimmten Zeitpunkt oder wiederkehrende Ausführung
- **Prioritätsbasierte Verarbeitung**: Wichtige Aufgaben werden vor weniger wichtigen verarbeitet
- **Fehlerbehandlung**: Automatische Wiederholungsversuche und detaillierte Fehlerprotokolle für fehlgeschlagene Jobs
- **Skalierbarkeit**: Flexibles Design für einfache Skalierung bei wachsender Last
- **Persistence**: Beständige Speicherung von Jobs mit MySQL-Backend

## Installation

### Voraussetzungen

- PHP 8.4 oder höher
- MySQL-Datenbank
- Composer (für Abhängigkeiten)

### Schritte

1. Dateien in Ihr Projekt kopieren
2. Datenbank vorbereiten:
   ```bash
   php migrations/queue_migration.php
   ```
3. Konfiguration anpassen:
    - `config/queue.php` für Queue-Konfigurationen
    - `config/database.php` für Datenbankeinstellungen

## Grundlegende Verwendung

### 1. Job definieren

Erstellen Sie eine Job-Klasse, die von `AbstractJob` erbt:

```php
<?php

namespace App\Jobs;

use Src\Queue\Job\AbstractJob;

class ProcessOrderJob extends AbstractJob
{
    private int $orderId;
    private array $options;
    
    public function __construct(int $orderId, array $options = [], ?string $id = null)
    {
        parent::__construct($id);
        $this->orderId = $orderId;
        $this->options = $options;
    }
    
    public function handle(): mixed
    {
        // Implementieren Sie hier Ihre Job-Logik
        // z.B. Bestellung verarbeiten, E-Mail senden, etc.
        
        return true; // Erfolgreiche Verarbeitung
    }
    
    protected function getData(): array
    {
        return [
            'order_id' => $this->orderId,
            'options' => $this->options
        ];
    }
    
    protected function setData(array $data): void
    {
        $this->orderId = $data['order_id'] ?? 0;
        $this->options = $data['options'] ?? [];
    }
}
```

### 2. Queue-Service einrichten

```php
<?php

use Src\Queue\Queue;
use Src\Container\Container;
use Src\Log\LoggerInterface;

// Container und Logger erstellen
$container = new Container();
$logger = $container->get(LoggerInterface::class);

// Queue-Service erstellen
$queue = new Queue($container, $logger);

// Queue-Konfigurationen registrieren
$configCreator = require_once __DIR__ . '/config/queue.php';
$queue->registerQueue('default', $configCreator['create_config']('default'));
$queue->registerQueue('emails', $configCreator['create_config']('emails'));
$queue->registerQueue('exports', $configCreator['create_config']('exports'));
```

### 3. Job in die Queue stellen

```php
<?php

use App\Jobs\ProcessOrderJob;

// Job erstellen
$job = new ProcessOrderJob(12345, ['notify' => true]);

// Job sofort zur Queue hinzufügen
$jobId = $queue->push('default', $job);

// Job mit Verzögerung zur Queue hinzufügen (1 Stunde)
$jobId = $queue->later('default', $job, 3600);

// Job mit Priorität zur Queue hinzufügen
$jobId = $queue->push('default', $job, null, 10);

// Job für einen bestimmten Zeitpunkt planen
$executeAt = new DateTime('2025-06-01 15:00:00');
$jobId = $queue->schedule('default', $job, $executeAt);
```

### 4. Worker starten

Verwenden Sie das bereitgestellte Worker-Skript, um Jobs zu verarbeiten:

```bash
# Verarbeitet Jobs aus der default-Queue
php bin/worker.php default

# Verarbeitet Jobs aus mehreren Queues
php bin/worker.php emails,default,exports

# Mit zusätzlichen Optionen
php bin/worker.php default --sleep=5 --max-jobs=100 --timeout=120 --verbose
```

### 5. Wiederkehrende Jobs einrichten

```php
<?php

use Src\Queue\Scheduler;

// Scheduler erstellen
$scheduler = new Scheduler($queue, $logger);

// Täglich um Mitternacht ausführen
$scheduler->scheduleRecurring(
    'daily-reports',
    'default',
    new GenerateReportJob(),
    '0 0 * * *'
);

// Alle 15 Minuten ausführen
$scheduler->scheduleRecurring(
    'check-emails',
    'emails',
    new CheckEmailsJob(),
    '*/15 * * * *'
);

// Scheduler in einem separaten Prozess oder Cron-Job ausführen
$scheduler->runDueJobs();
```

## Erweiterte Funktionen

### Eindeutige Jobs

Verhindern Sie Duplikate, indem Sie Jobs als eindeutig markieren:

```php
<?php

$job = new ProcessOrderJob(12345);
$job->makeUnique(); // Verwendet die Job-Daten für die Eindeutigkeit

// Oder mit benutzerdefiniertem Schlüssel
$job->makeUnique('order-12345');

$queue->push('default', $job);
```

### Job-Timeout

Setzen Sie einen Timeout für Jobs, die zu lange laufen:

```php
<?php

$job = new ProcessOrderJob(12345);
$job->setTimeout(120); // 2 Minuten

$queue->push('default', $job);
```

### Fehlgeschlagene Jobs erneut ausführen

```php
<?php

// Fehlgeschlagene Jobs abrufen
$failedJobs = $queue->getFailedJobs('default', 10, 0);

// Job erneut ausführen
$queue->retry('default', $failedJobs[0]['id']);
```

### Statistiken abrufen

```php
<?php

// Allgemeine Queue-Statistiken
$stats = $queue->getStats();

// Statistiken für eine bestimmte Queue
$stats = $queue->getStats('emails');
```

## Konfiguration

### Wiederholungsstrategien

- `RetryStrategy::FIXED` - Konstante Verzögerung zwischen Wiederholungen
- `RetryStrategy::LINEAR` - Linear ansteigende Verzögerung
- `RetryStrategy::EXPONENTIAL` - Exponentiell ansteigende Verzögerung

### Prioritäten

- Höhere Zahlen = Höhere Priorität
- Standard: 0
- Bereich: -1000 bis 1000

## Systemarchitektur

- **Queue**: Hauptklasse für das Hinzufügen von Jobs und die Interaktion mit dem Queue-System
- **Worker**: Verarbeitet Jobs aus einer oder mehreren Queues
- **Scheduler**: Verwaltet wiederkehrende und zeitgesteuerte Jobs
- **Job**: Repräsentiert eine ausführbare Aufgabe
- **Connection**: Backend-spezifische Implementierung für die Jobspeicherung (MySQL, Redis, etc.)

## Fehlerbehandlung

### Automatische Wiederholungsversuche

Fehlgeschlagene Jobs werden automatisch wiederholt, basierend auf der konfigurierten Wiederholungsstrategie:

```php
<?php

// In der Queue-Konfiguration
$queueConfig->setMaxRetries(3);
$queueConfig->setRetryDelay(60);
$queueConfig->setRetryStrategy(RetryStrategy::EXPONENTIAL);
```

### Benutzerdefinierte Fehlerbehandlung

Implementieren Sie die `failed()`-Methode in Ihrem Job:

```php
<?php

public function failed(\Throwable $exception): void
{
    // Benutzerdefinierte Behandlung fehlgeschlagener Jobs
    // z.B. Benachrichtigung senden, Status aktualisieren, etc.
}
```

## Performance-Optimierung

- **Batching**: Verarbeiten Sie mehrere Jobs in einem Durchgang
- **Job-Priorität**: Stellen Sie sicher, dass wichtige Jobs zuerst verarbeitet werden
- **Worker-Konfiguration**: Passen Sie Sleep-Zeit, Memory-Limits und Timeouts an
- **Skalierung**: Starten Sie mehrere Worker für parallele Verarbeitung

## Beispiele

### E-Mail-Versand im Hintergrund

```php
<?php

use App\Jobs\SendEmailJob;

$job = new SendEmailJob(
    'kunde@example.com',
    'Bestellbestätigung',
    'Vielen Dank für Ihre Bestellung #12345.',
    ['attach_invoice' => true]
);

$queue->push('emails', $job);
```

### Datenexport mit Scheduling

```php
<?php

use App\Jobs\ExportDataJob;

$job = new ExportDataJob('users', ['format' => 'csv']);

// Nächsten Sonntag um 3 Uhr morgens
$executeAt = new DateTime('next Sunday 03:00:00');
$queue->schedule('exports', $job, $executeAt);
```

### Tägliche Bereinigung mit wiederkehrenden Jobs

```php
<?php

use App\Jobs\CleanupJob;

$scheduler->scheduleRecurring(
    'daily-cleanup',
    'default',
    new CleanupJob(),
    '0 2 * * *' // Jeden Tag um 2 Uhr morgens
);
```

## Lizenz

MIT-Lizenz