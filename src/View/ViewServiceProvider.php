<?php

namespace Src\View;

use Src\Container\Container;
use Src\Http\Router;
use Src\Log\LoggerInterface;
use Src\View\Cache\FilesystemTemplateCache;
use Src\View\Compiler\TemplateCompiler;
use Src\View\Functions\DefaultFunctions;
use Src\View\Loader\FilesystemTemplateLoader;

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

        // Template-Loader registrieren
        $container->register(FilesystemTemplateLoader::class, function () use ($config, $logger) {
            $loader = new FilesystemTemplateLoader($config['template_dir']);
            $logger->debug("Template loader registered");
            return $loader;
        });

        // Template-Cache registrieren
        $container->register(FilesystemTemplateCache::class, function () use ($config, $logger) {
            $cache = new FilesystemTemplateCache($config['cache_dir'], $config['use_cache']);
            $logger->debug("Template cache registered");
            return $cache;
        });

        // Template-Compiler registrieren
        $container->register(TemplateCompiler::class, function () use ($logger) {
            $compiler = new TemplateCompiler();
            $logger->debug("Template compiler registered");
            return $compiler;
        });

        // Template-Engine registrieren - KORRIGIERTE REIHENFOLGE DER PARAMETER
        $container->register(TemplateEngine::class, function () use ($container, $logger) {
            $loader = $container->get(FilesystemTemplateLoader::class);
            $cache = $container->get(FilesystemTemplateCache::class);
            $compiler = $container->get(TemplateCompiler::class);

            $engine = new TemplateEngine($loader, $cache, $compiler);

            // Standard-Hilfsfunktionen registrieren
            $this->registerDefaultTemplateFunctions($engine);

            $logger->debug("Template engine registered with default functions");
            return $engine;
        });

        // ViewFactory registrieren
        $container->register(ViewFactory::class, function () use ($container, $logger) {
            $engine = $container->get(TemplateEngine::class);
            $factory = new ViewFactory($engine, $logger);

            // Router für URL-Generierung setzen, falls verfügbar
            if ($container->has(Router::class)) {
                $router = $container->get(Router::class);

                // DefaultFunctions mit Router registrieren
                $defaultFunctions = new DefaultFunctions($router);
                $engine->registerFunctionProvider($defaultFunctions);

                $logger->debug("Router set in DefaultFunctions");
            } else {
                // DefaultFunctions ohne Router registrieren
                $defaultFunctions = new DefaultFunctions();
                $engine->registerFunctionProvider($defaultFunctions);

                $logger->warning("Router not available for DefaultFunctions");
            }

            return $factory;
        });

        // Alias für 'view' registrieren
        $container->register('view', function () use ($container) {
            return $container->get(ViewFactory::class);
        });

        $logger->info("View services registered successfully");
    }

    /**
     * Registriert die Standard-Template-Funktionen
     *
     * @param TemplateEngine $engine Die Template-Engine
     * @return void
     */
    private function registerDefaultTemplateFunctions(TemplateEngine $engine): void
    {
        // URL-Funktion
        $engine->registerFunction('url', function (string $route, array $params = []) {
            // Einfache Implementierung
            $url = '/' . trim($route, '/');

            // Parameter als Query-String hinzufügen
            if (!empty($params)) {
                $queryString = http_build_query($params);
                $url .= '?' . $queryString;
            }

            return $url;
        });

        // DateFormat-Funktion
        $engine->registerFunction('dateFormat', function ($date, $format = 'd.m.Y') {
            if ($date instanceof \DateTime) {
                return $date->format($format);
            }

            if (is_string($date)) {
                return date($format, strtotime($date));
            }

            return '';
        });

        // Length-Funktion für Arrays und Strings
        $engine->registerFunction('length', function ($value) {
            if (is_array($value) || $value instanceof \Countable) {
                return count($value);
            }

            if (is_string($value)) {
                return mb_strlen($value, 'UTF-8');
            }

            return 0;
        });

        // JSON-Funktion
        $engine->registerFunction('json', function ($value, $options = 0) {
            return json_encode($value, $options | JSON_UNESCAPED_UNICODE);
        });

        // Escape-Funktion (bereits als 'e' in der Engine registriert, aber für Vollständigkeit)
        $engine->registerFunction('escape', function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8', false);
        });

        // Dump-Funktion für Debugging
        $engine->registerFunction('dump', function ($value) {
            ob_start();
            var_dump($value);
            $dump = ob_get_clean();
            return '<pre>' . htmlspecialchars($dump, ENT_QUOTES, 'UTF-8') . '</pre>';
        });

        // Upper-Funktion für Strings
        $engine->registerFunction('upper', function ($value) {
            return mb_strtoupper((string)$value, 'UTF-8');
        });

        // Lower-Funktion für Strings
        $engine->registerFunction('lower', function ($value) {
            return mb_strtolower((string)$value, 'UTF-8');
        });

        // Formatierung von Zahlen
        $engine->registerFunction('number_format', function ($value, $decimals = 2, $dec_point = ',', $thousands_sep = '.') {
            return number_format((float)$value, $decimals, $dec_point, $thousands_sep);
        });

        // Wenn Sie einen Router haben, könnten Sie hier eine fortgeschrittenere URL-Funktion erstellen
    }
}