<?php

/**
 * Einstiegspunkt für die Anwendung
 *
 * PHP Version 8.4
 */

// Define the application base path
define('BASE_PATH', dirname(__DIR__));

// Performance-Messung starten
define('APP_START', microtime(true));

// Fehlerberichterstattung für die Entwicklungsumgebung
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Register the Composer autoloader
require BASE_PATH . '/vendor/autoload.php';

// Instanz der Anwendung erstellen und als Singleton speichern
$app = Src\Application::getInstance();

// Aktuellen Request verarbeiten
$app->handle();

// Debug-Informationen in Entwicklungsumgebung
if ($app->getConfig()->get('app.debug', false)) {
    $executionTime = round((microtime(true) - APP_START) * 1000, 2);
    $memoryUsage = round(memory_get_peak_usage() / 1024 / 1024, 2);
    error_log("Ausführungszeit: {$executionTime}ms | Speichernutzung: {$memoryUsage}MB");
}