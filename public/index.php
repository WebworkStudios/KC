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

// Bootstrapping
$startTime = microtime(true);

// Autoloader laden
require_once __DIR__ . '/../vendor/autoload.php';

// Hilfsfunktionen laden
require_once __DIR__ . '/../src/functions.php';

// Container initialisieren
$container = new Src\Container\Container();

// Request erstellen
$request = Src\Http\Request::fromGlobals();

// Router initialisieren
$router = new Src\Http\Router($container);

// Actions registrieren
$router->registerActionsFromDirectory('App\\Actions', __DIR__ . '/../app/Actions');

// Request dispatchen
$response = $router->dispatch($request);

// 404 wenn keine Route gefunden wurde
if ($response === null) {
    $response = new Src\Http\Response('Not Found', 404);
}

// Response senden
$response->send();

// Debug-Informationen (nur in Entwicklungsumgebung)
if (defined('DEBUG') && DEBUG) {
    $executionTime = microtime(true) - $startTime;
    echo "<!-- Ausführungszeit: {$executionTime}s -->";
}