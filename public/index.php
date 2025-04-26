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

// Debug-Modus für Entwicklung
define('DEBUG', true);
define('BASE_PATH', __DIR__ . '/..');
define('APP_URL', 'http://kickerscup.local');

// Bootstrapping
$startTime = microtime(true);

// Autoloader laden
require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap-Datei laden
require_once __DIR__ . '/../app/bootstrap.php';

// Container initialisieren
$container = bootstrapContainer([
    'app' => [
        'name' => 'PHP 8.4 ADR Framework',
        'environment' => DEBUG ? 'development' : 'production',
        'debug' => DEBUG,
        'url' => APP_URL,
    ],
]);

// Logger abrufen
$logger = $container->get(Src\Log\LoggerInterface::class);

// Request erstellen
$request = Src\Http\Request::fromGlobals();

// Router initialisieren
try {
    $router = new Src\Http\Router($container);
    $logger->debug('Router initialisiert');

    // Prüfen, ob das Actions-Verzeichnis existiert
    $actionsDir = __DIR__ . '/../app/Actions';
    if (!is_dir($actionsDir)) {
        throw new RuntimeException("Actions-Verzeichnis nicht gefunden: $actionsDir");
    }

    // Actions registrieren
    $router->registerActionsFromDirectory('App\\Actions', $actionsDir);
    $logger->info('Actions registriert aus Verzeichnis: ' . $actionsDir);

    // LoggingMiddleware als globale Middleware verwenden
    $loggingMiddleware = $container->get(Src\Http\Middleware\LoggingMiddleware::class);

    // Request dispatchen
    $response = $router->dispatch($request);

    // 404 wenn keine Route gefunden wurde
    if ($response === null) {
        $logger->warning('Keine Route gefunden für: ' . $request->getPath());
        $response = new Src\Http\Response('Not Found', 404);
    }
} catch (Throwable $e) {
    // Fehler loggen
    Src\Log\LogException::log(
        $logger,
        $e,
        'error',
        'Fehler beim Verarbeiten der Anfrage',
        ['request_path' => $request->getPath()]
    );

    // Fehlerseite für Entwicklungsumgebung
    if (defined('DEBUG') && DEBUG) {
        $response = new Src\Http\Response(
            '<h1>Fehler</h1><pre>' . $e->getMessage() . "\n" . $e->getTraceAsString() . '</pre>',
            500,
            'text/html'
        );
    } else {
        // Produktionsumgebung: Allgemeine Fehlermeldung
        $response = new Src\Http\Response('Internal Server Error', 500);
    }
}

// WICHTIG: Response an Client senden
$response->send();

// Performance-Messung für Entwicklung
$executionTime = microtime(true) - $startTime;
$logger->debug('Anfrage verarbeitet in ' . number_format($executionTime * 1000, 2) . ' ms', [
    'execution_time_ms' => $executionTime * 1000,
    'memory_usage' => memory_get_usage(true),
    'memory_peak' => memory_get_peak_usage(true)
]);

// Nur im Debug-Modus in error_log schreiben
if (DEBUG) {
    error_log("Ausführungszeit: " . number_format($executionTime * 1000, 2) . " ms");
}