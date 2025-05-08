<?php

namespace Src\View;

use Src\Container\Container;
use Src\Http\Router;
use Src\Log\LoggerInterface;

/**
 * Service-Provider für die Template-Engine und ViewFactory
 */
class ViewServiceProvider
{
    /**
     * Registriert die Template-Engine-Services im Container
     *
     * @param Container $container DI-Container
     * @param array $config Service-Konfiguration
     * @return void
     */
    public function register(Container $container, array $config = []): void
    {
        // Get logger
        $logger = $container->get(LoggerInterface::class);

        // Standard-Konfiguration mit übergebener Konfiguration zusammenführen
        $config = array_merge([
            'template_dir' => dirname(__DIR__, 2) . '/resources/views',
            'cache_dir' => dirname(__DIR__, 2) . '/storage/framework/views',
            'use_cache' => !($config['app']['debug'] ?? false),
        ], $config);

        $logger->debug("Configuring template engine", [
            'template_dir' => $config['template_dir'],
            'cache_dir' => $config['cache_dir'],
            'use_cache' => $config['use_cache']
        ]);

        // Template-Engine registrieren
        $container->register(TemplateEngine::class, function () use ($config, $logger) {
            $engine = new TemplateEngine(
                $config['template_dir'],
                $config['cache_dir'],
                $config['use_cache']
            );

            $logger->debug("Template engine registered");
            return $engine;
        });

        // ViewFactory registrieren
        $container->register(ViewFactory::class, function () use ($container, $logger) {
            $engine = $container->get(TemplateEngine::class);
            $factory = new ViewFactory($engine, $logger);

            // Router für URL-Generierung setzen, falls verfügbar
            if ($container->has(Router::class)) {
                $router = $container->get(Router::class);
                $factory->setRouter($router);
                $logger->debug("Router set in ViewFactory");
            } else {
                $logger->warning("Router not available for ViewFactory");
            }

            return $factory;
        });

        // Alias für 'view' registrieren
        $container->register('view', function () use ($container) {
            return $container->get(ViewFactory::class);
        });

        $logger->info("View services registered successfully");
    }
}