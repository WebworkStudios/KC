<?php

/**
 * Einstiegspunkt für die Anwendung
 *
 * Diese Datei dient als Einstiegspunkt für alle HTTP-Anfragen und implementiert
 * den grundlegenden Request-Routing-Mechanismus nach dem ADR-Pattern
 * (Action-Domain-Responder)
 *
 * PHP Version 8.4
 */

declare(strict_types=1);

// Fehlerbehandlung für Entwicklung
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Debug-Modus und Umgebungskonstanten
define('DEBUG', true);
define('ENVIRONMENT', 'development'); // 'development', 'testing', 'production'
define('BASE_PATH', dirname(__DIR__));
define('APP_URL', 'http://localhost:8000');

// Performance-Tracking
$startTime = microtime(true);

// Autoloader laden
require_once BASE_PATH . '/vendor/autoload.php';

// Basis-Konfiguration laden
$config = [
    'app' => [
        'name' => 'PHP 8.4 ADR Framework',
        'environment' => ENVIRONMENT,
        'debug' => DEBUG,
        'url' => APP_URL,
    ],
];

// Bootstrap-Datei laden
require_once BASE_PATH . '/app/bootstrap.php';

try {
    // Container initialisieren
    $container = bootstrapContainer($config);

    // Logger abrufen
    $logger = $container->get(Src\Log\LoggerInterface::class);
    $logger->info('Anwendung gestartet', ['environment' => ENVIRONMENT]);

    // Session initialisieren
    bootstrapSession($container, $config);

    // Cache initialisieren
    bootstrapCache($container, $config);

    // Request erstellen
    $request = Src\Http\Request::fromGlobals();

    // Router initialisieren
    $router = new Src\Http\Router($container);
    $logger->debug('Router initialisiert');

    // Prüfen, ob das Actions-Verzeichnis existiert
    $actionsDir = BASE_PATH . '/app/Actions';
    if (!is_dir($actionsDir)) {
        throw new RuntimeException("Actions-Verzeichnis nicht gefunden: $actionsDir");
    }

    // Actions registrieren
    $router->registerActionsFromDirectory('App\\Actions', $actionsDir);
    $logger->info('Actions registriert aus Verzeichnis: ' . $actionsDir);

    // Globale Middlewares registrieren
    $middlewares = [
        $container->get(Src\Http\Middleware\LoggingMiddleware::class),
    ];

    // CSRF-Middleware aktivieren, wenn vorhanden
    if ($container->has(Src\Http\Middleware\CsrfMiddleware::class) &&
        ($config['session']['csrf']['enabled'] ?? true)) {
        $middlewares[] = $container->get(Src\Http\Middleware\CsrfMiddleware::class);
    }

    // Cache-Middleware aktivieren, wenn vorhanden
    if ($container->has(Src\Http\Middleware\CacheMiddleware::class) &&
        ($config['cache']['http']['enabled'] ?? false)) {
        $middlewares[] = $container->get(Src\Http\Middleware\CacheMiddleware::class);
    }

    // Request durch Middleware-Stack leiten
    $processedRequest = $request;
    foreach ($middlewares as $middleware) {
        $response = $middleware->process($processedRequest, function($req) use (&$processedRequest) {
            $processedRequest = $req;
            return null;
        });

        // Wenn Middleware eine Response zurückgibt, direkt zurückgeben
        if ($response instanceof Src\Http\Response) {
            $logger->info('Response von Middleware erzeugt', [
                'middleware' => get_class($middleware),
                'status' => $response->getStatus()
            ]);
            $response->send();
            exit;
        }
    }

    // Request dispatchen
    $response = $router->dispatch($processedRequest);

    // 404 wenn keine Route gefunden wurde
    if ($response === null) {
        $logger->warning('Keine Route gefunden für: ' . $request->getPath());
        $response = new Src\Http\Response('Not Found', 404);
    }
} catch (Throwable $e) {
    // Fehler loggen
    if (isset($logger)) {
        Src\Log\LogException::log(
            $logger,
            $e,
            'error',
            'Fehler beim Verarbeiten der Anfrage',
            ['request_path' => $request->getPath() ?? '/']
        );
    } else {
        // Fallback, wenn Logger nicht verfügbar
        error_log('Schwerwiegender Fehler: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        error_log($e->getTraceAsString());
    }

    // Fehlerseite für Entwicklungsumgebung
    if (DEBUG) {
        $response = new Src\Http\Response(
            buildDebugErrorPage($e),
            500,
            'text/html'
        );
    } else {
        // Produktionsumgebung: Allgemeine Fehlermeldung
        $response = new Src\Http\Response('Internal Server Error', 500);
    }
}

// Response an Client senden
$response->send();

// Performance-Messung
$executionTime = microtime(true) - $startTime;
if (isset($logger)) {
    $logger->debug('Anfrage verarbeitet in ' . number_format($executionTime * 1000, 2) . ' ms', [
        'execution_time_ms' => $executionTime * 1000,
        'memory_usage' => formatBytes(memory_get_usage(true)),
        'memory_peak' => formatBytes(memory_get_peak_usage(true))
    ]);
}

// Nur im Debug-Modus in error_log schreiben
if (DEBUG) {
    error_log("Ausführungszeit: " . number_format($executionTime * 1000, 2) . " ms");
}

/**
 * Erzeugt eine Debug-Fehlerseite für die Entwicklungsumgebung
 *
 * @param Throwable $e Die aufgetretene Exception
 * @return string HTML-Code der Fehlerseite
 */
function buildDebugErrorPage(Throwable $e): string {
    $title = get_class($e);
    $message = htmlspecialchars($e->getMessage());
    $file = htmlspecialchars($e->getFile());
    $line = $e->getLine();
    $trace = htmlspecialchars($e->getTraceAsString());

    return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fehler: {$title}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        .error-container {
            background-color: #f8f8f8;
            border-left: 5px solid #e74c3c;
            padding: 1rem 2rem;
            margin-bottom: 2rem;
            border-radius: 0 4px 4px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #e74c3c;
            margin-top: 0;
        }
        .file-info {
            background-color: #eee;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-family: monospace;
            margin-bottom: 1rem;
        }
        .stack-trace {
            background-color: #2d3436;
            color: #dfe6e9;
            padding: 1rem;
            border-radius: 4px;
            font-family: monospace;
            overflow-x: auto;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 2rem;
            font-size: 0.9rem;
            color: #7f8c8d;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>{$title}</h1>
        <p>{$message}</p>
        <div class="file-info">
            {$file}:{$line}
        </div>
    </div>
    
    <h2>Stack Trace</h2>
    <div class="stack-trace">{$trace}</div>
    
    <div class="footer">
        PHP 8.4 ADR Framework - Debug-Modus ist aktiv
    </div>
</body>
</html>
HTML;
}

/**
 * Formatiert Bytes in lesbare Größe
 *
 * @param int $bytes Bytezahl
 * @param int $precision Nachkommastellen
 * @return string Formatierte Größe
 */
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= (1 << (10 * $pow));

    return round($bytes, $precision) . ' ' . $units[$pow];
}