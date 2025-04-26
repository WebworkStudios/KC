<?php

/**
 * Bootstrap für die Anwendung
 *
 * Initialisiert den Container, die Konfiguration und andere Kernkomponenten
 *
 * PHP Version 8.4
 */

declare(strict_types=1);

use Src\Config;
use Src\Container\Container;
use Src\Log\LoggerFactory;
use Src\Log\LoggerInterface;
use Src\Http\Middleware\LoggingMiddleware;

/**
 * Container initialisieren und konfigurieren
 *
 * @param array $config Zusätzliche Konfiguration
 * @return Container Konfigurierter Container
 */
function bootstrapContainer(array $config = []): Container
{
    // Container erstellen
    $container = new Container();

    // Konfiguration registrieren
    $appConfig = new Config($config);
    $container->register(Config::class, $appConfig);

    // Logger einrichten
    $loggerFactory = new LoggerFactory(
        $appConfig->get('logging.dir'),
        $appConfig->get('logging.level', 'debug')
    );

    $container->register(LoggerFactory::class, $loggerFactory);

    // Standard-Logger erstellen und registrieren
    $environment = $appConfig->get('app.environment', 'development');
    $logger = $loggerFactory->createLogger('file', [
        'filename' => 'container.log',
        'level' => $appConfig->get('logging.level', 'debug'),
    ]);

    // Logger im Container registrieren
    $container->register(LoggerInterface::class, $logger);

    // Logger im Container selbst setzen
    $container->setLogger($logger);

    // Verbose Logging im Entwicklungsmodus aktivieren
    if ($environment === 'development') {
        $container->setVerboseLogging(true);
    }

    // LoggingMiddleware registrieren
    $container->register(LoggingMiddleware::class);

    return $container;
}