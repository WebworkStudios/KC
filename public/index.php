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

// Container initialisieren
$container = new Src\Container\Container();
