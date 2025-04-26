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

// Container initialisieren
$container = new Src\Container\Container();

// Request erstellen
$request = Src\Http\Request::fromGlobals();

// Router initialisieren
try {
    $router = new Src\Http\Router($container);

    // Prüfen, ob das Actions-Verzeichnis existiert
    $actionsDir = __DIR__ . '/../app/Actions';
    if (!is_dir($actionsDir)) {
        throw new RuntimeException("Actions-Verzeichnis nicht gefunden: $actionsDir");
    }

    // Actions registrieren
    $router->registerActionsFromDirectory('App\\Actions', $actionsDir);

    // HelloWorldAction für den Anfang manuell registrieren
    $router->registerAction('App\\Actions\\HelloWorldAction');

    // Request dispatchen
    $response = $router->dispatch($request);

    // 404 wenn keine Route gefunden wurde
    if ($response === null) {
        $response = new Src\Http\Response('Not Found', 404);
    }
} catch (Throwable $e) {
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

    // Fehler loggen
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
}

// WICHTIG: Response an Client senden
$response->send();

// Performance-Messung für Entwicklung
if (DEBUG) {
    $executionTime = microtime(true) - $startTime;
    error_log("Ausführungszeit: " . number_format($executionTime * 1000, 2) . " ms");
}