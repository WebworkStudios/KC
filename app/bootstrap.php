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
    $logger = $loggerFactory->createDefaultLogger($environment, [
        'level' => $appConfig->get('logging.level', 'debug'),
        'filename' => $appConfig->get('logging.file.filename', 'app.log'),
    ]);

    $container->register(LoggerInterface::class, $logger);

    // LoggingMiddleware registrieren
    $container->register(LoggingMiddleware::class);

    return $container;
}