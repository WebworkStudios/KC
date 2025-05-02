<?php


namespace Src\View;

use Src\Container\Container;
use Src\Http\Router;

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
        // Standard-Konfiguration mit übergebener Konfiguration zusammenführen
        $config = array_merge([
            'template_dir' => dirname(__DIR__, 2) . '/resources/views',
            'cache_dir' => dirname(__DIR__, 2) . '/storage/framework/views',
            'use_cache' => !($config['app']['debug'] ?? false),
        ], $config);

        // Template-Engine registrieren
        $container->register(TemplateEngine::class, function () use ($config) {
            return new TemplateEngine(
                $config['template_dir'],
                $config['cache_dir'],
                $config['use_cache']
            );
        });

        // ViewFactory registrieren
        $container->register(ViewFactory::class, function () use ($container) {
            $engine = $container->get(TemplateEngine::class);
            $factory = new ViewFactory($engine);

            // Router für URL-Generierung setzen, falls verfügbar
            if ($container->has(Router::class)) {
                $factory->setRouter($container->get(Router::class));
            }

            return $factory;
        });

        // Alias für 'view' registrieren
        $container->register('view', function () use ($container) {
            return $container->get(ViewFactory::class);
        });
    }
}