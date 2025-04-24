<?php

/**
 * Einstiegspunkt für die Anwendung
 *
 * PHP Version 8.4
 */

// Define the application base path
define('BASE_PATH', dirname(__DIR__));

// Register the Composer autoloader
require BASE_PATH . '/vendor/autoload.php';

// Create the application
$app = new Src\Application();

// Handle the current request
$app->handle();