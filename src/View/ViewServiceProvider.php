<?php

namespace Src\View;

use Src\Container\Container;
use Src\Http\Router;
use Src\Log\LoggerInterface;
use Src\View\Cache\FilesystemTemplateCache;
use Src\View\Compiler\TemplateCompiler;
use Src\View\Functions\DefaultFunctions;
use Src\View\Loader\FilesystemTemplateLoader;
use Throwable;

/**
 * Verbesserter Service-Provider für die Template-Engine
 *
 * Kritische Verbesserungen:
 * - Korrigierte TemplateEngine-Parameter-Reihenfolge
 * - Bessere Fehlerbehandlung
 * - Robuste Verzeichnis-Erstellung
 * - Erweiterte Konfigurationsoptionen
 */
class ViewServiceProvider
{
    /**
     * Registriert die Template-Engine-Services im Container
     */
    public function register(Container $container, array $config = []): void
    {
        $logger = $container->get(LoggerInterface::class);

        try {
            // Standard-Konfiguration mit besseren Defaults
            $config = $this->mergeDefaultConfig($config);

            $logger->debug("Configuring template engine with improved settings", [
                'template_dir' => $config['template_dir'],
                'cache_dir' => $config['cache_dir'],
                'use_cache' => $config['use_cache'],
                'debug_mode' => $config['debug_mode'] ?? false
            ]);

            // Verzeichnisse sicherstellen
            $this->ensureDirectories($config, $logger);

            // Services registrieren
            $this->registerLoader($container, $config, $logger);
            $this->registerCompiler($container, $config, $logger);
            $this->registerCache($container, $config, $logger);
            $this->registerEngine($container, $config, $logger);
            $this->registerFactory($container, $config, $logger);

            $logger->info("View services registered successfully");

        } catch (Throwable $e) {
            $logger->error("Failed to register view services: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Führt Standard-Konfiguration mit übergebener Konfiguration zusammen
     */
    private function mergeDefaultConfig(array $config): array
    {
        $basePath = dirname(__DIR__, 2);

        return array_merge([
            'template_dir' => $basePath . '/resources/views',
            'cache_dir' => $basePath . '/storage/framework/views',
            'use_cache' => !($config['app']['debug'] ?? false),
            'debug_mode' => $config['app']['debug'] ?? false,
            'template_extension' => '.php',
            'cache_permissions' => [
                'directory' => 0755,
                'file' => 0644
            ],
            'auto_reload' => $config['app']['debug'] ?? false,
            'max_cache_size' => 1000,
            'enable_gc' => true,
            'gc_probability' => 0.01 // 1% Chance auf Garbage Collection
        ], $config);
    }

    /**
     * Stellt sicher, dass notwendige Verzeichnisse existieren
     */
    private function ensureDirectories(array $config, LoggerInterface $logger): void
    {
        $directories = [
            'template_dir' => $config['template_dir'],
            'cache_dir' => $config['cache_dir']
        ];

        foreach ($directories as $type => $path) {
            if (!is_dir($path)) {
                $logger->debug("Creating directory: {$path}");

                if (!mkdir($path, $config['cache_permissions']['directory'], true) && !is_dir($path)) {
                    throw new \RuntimeException("Failed to create {$type}: {$path}");
                }
            }

            if ($type === 'cache_dir' && !is_writable($path)) {
                throw new \RuntimeException("Cache directory is not writable: {$path}");
            }

            $logger->debug("Directory verified: {$path}");
        }
    }

    /**
     * Registriert den Template-Loader
     */
    private function registerLoader(Container $container, array $config, LoggerInterface $logger): void
    {
        $container->register(FilesystemTemplateLoader::class, function () use ($config, $logger) {
            $loader = new FilesystemTemplateLoader(
                $config['template_dir'],
                $config['template_extension']
            );

            $logger->debug("Template loader registered", [
                'template_dir' => $config['template_dir'],
                'extension' => $config['template_extension']
            ]);

            return $loader;
        });
    }

    /**
     * Registriert den Template-Compiler
     */
    private function registerCompiler(Container $container, array $config, LoggerInterface $logger): void
    {
        $container->register(TemplateCompiler::class, function () use ($config, $logger) {
            $compiler = new TemplateCompiler();

            // Erweiterte Compiler-Konfiguration falls vorhanden
            if (isset($config['compiler'])) {
                // Hier könnten zusätzliche Compiler-Passes registriert werden
                $compilerConfig = $config['compiler'];

                if (isset($compilerConfig['custom_passes'])) {
                    foreach ($compilerConfig['custom_passes'] as $passClass) {
                        if (class_exists($passClass)) {
                            $compiler->registerPass(new $passClass());
                            $logger->debug("Custom compiler pass registered: {$passClass}");
                        }
                    }
                }
            }

            $logger->debug("Template compiler registered");
            return $compiler;
        });
    }

    /**
     * Registriert den Template-Cache
     */
    private function registerCache(Container $container, array $config, LoggerInterface $logger): void
    {
        $container->register(FilesystemTemplateCache::class, function () use ($config, $logger) {
            $cache = new FilesystemTemplateCache(
                $config['cache_dir'],
                $config['use_cache']
            );

            // Erweiterte Cache-Konfiguration
            if (isset($config['max_cache_size'])) {
                $cache->setMaxCacheSize($config['max_cache_size']);
            }

            // Garbage Collection konfigurieren
            if ($config['enable_gc'] && $config['use_cache']) {
                $this->scheduleGarbageCollection($cache, $config, $logger);
            }

            $logger->debug("Template cache registered", [
                'cache_dir' => $config['cache_dir'],
                'enabled' => $config['use_cache'],
                'max_size' => $config['max_cache_size'] ?? 'default'
            ]);

            return $cache;
        });
    }

    /**
     * Registriert die Template-Engine mit korrigierter Parameter-Reihenfolge
     */
    private function registerEngine(Container $container, array $config, LoggerInterface $logger): void
    {
        $container->register(TemplateEngine::class, function () use ($container, $config, $logger) {
            $loader = $container->get(FilesystemTemplateLoader::class);
            $compiler = $container->get(TemplateCompiler::class);
            $cache = $container->get(FilesystemTemplateCache::class);

            // KORRIGIERTE Parameter-Reihenfolge: loader, compiler, cache
            $engine = new TemplateEngine($loader, $compiler, $cache);

            // Debug-Modus setzen
            if ($config['debug_mode']) {
                $engine->setDebugMode(true);
            }

            // Standard-Hilfsfunktionen sind bereits in TemplateEngine registriert
            $logger->debug("Template engine registered with corrected parameter order");

            return $engine;
        });
    }

    /**
     * Registriert die ViewFactory
     */
    private function registerFactory(Container $container, array $config, LoggerInterface $logger): void
    {
        $container->register(ViewFactory::class, function () use ($container, $config, $logger) {
            $engine = $container->get(TemplateEngine::class);
            $factory = new ViewFactory($engine, $logger);

            // Debug-Modus setzen
            if ($config['debug_mode']) {
                $factory->setDebugMode(true);
            }

            // Router setzen, falls verfügbar
            if ($container->has(Router::class)) {
                try {
                    $router = $container->get(Router::class);
                    $factory->setRouter($router);

                    // DefaultFunctions mit Router registrieren
                    $defaultFunctions = new DefaultFunctions($router);
                    $engine->registerFunctionProvider($defaultFunctions);

                    $logger->debug("Router set in ViewFactory and DefaultFunctions registered");
                } catch (Throwable $e) {
                    $logger->warning("Failed to set router in ViewFactory: " . $e->getMessage());
                }
            } else {
                // DefaultFunctions ohne Router registrieren
                $defaultFunctions = new DefaultFunctions();
                $engine->registerFunctionProvider($defaultFunctions);

                $logger->warning("Router not available for ViewFactory");
            }

            // Globale Template-Variablen setzen
            $this->setGlobalVariables($factory, $config, $logger);

            // Custom Function Providers registrieren
            $this->registerCustomFunctionProviders($factory, $config, $logger);

            return $factory;
        });

        // Alias für 'view' registrieren
        $container->register('view', function () use ($container) {
            return $container->get(ViewFactory::class);
        });
    }

    /**
     * Setzt globale Template-Variablen
     */
    private function setGlobalVariables(ViewFactory $factory, array $config, LoggerInterface $logger): void
    {
        $globalVars = [
            'app_name' => $config['app']['name'] ?? 'Application',
            'app_env' => $config['app']['environment'] ?? 'production',
            'app_debug' => $config['app']['debug'] ?? false,
            'app_version' => $config['app']['version'] ?? '1.0.0',
            'base_url' => $this->getBaseUrl(),
            'current_year' => date('Y'),
            'current_timestamp' => time()
        ];

        // Zusätzliche globale Variablen aus Konfiguration
        if (isset($config['view']['global_variables'])) {
            $globalVars = array_merge($globalVars, $config['view']['global_variables']);
        }

        $factory->share($globalVars);

        $logger->debug("Global template variables set", [
            'variables' => array_keys($globalVars)
        ]);
    }

    /**
     * Registriert benutzerdefinierte Function Providers
     */
    private function registerCustomFunctionProviders(ViewFactory $factory, array $config, LoggerInterface $logger): void
    {
        if (!isset($config['view']['function_providers'])) {
            return;
        }

        foreach ($config['view']['function_providers'] as $providerClass) {
            try {
                if (class_exists($providerClass)) {
                    $provider = new $providerClass();

                    if ($provider instanceof FunctionProviderInterface) {
                        $factory->registerFunctionProvider($provider);
                        $logger->debug("Custom function provider registered: {$providerClass}");
                    } else {
                        $logger->warning("Class {$providerClass} does not implement FunctionProviderInterface");
                    }
                } else {
                    $logger->warning("Function provider class not found: {$providerClass}");
                }
            } catch (Throwable $e) {
                $logger->error("Failed to register function provider {$providerClass}: " . $e->getMessage());
            }
        }
    }

    /**
     * Registriert erweiterte Template-Funktionen
     */
    private function registerExtendedTemplateFunctions(ViewFactory $factory, LoggerInterface $logger): void
    {
        // CSRF-Token-Funktionen
        $factory->registerFunction('csrf_token', function (): string {
            if (!isset($_SESSION)) {
                session_start();
            }

            if (!isset($_SESSION['_token'])) {
                $_SESSION['_token'] = bin2hex(random_bytes(32));
            }

            return $_SESSION['_token'];
        });

        $factory->registerFunction('csrf_field', function (): string {
            $token = $factory->getEngine()->callFunction('csrf_token', []);
            return '<input type="hidden" name="_token" value="' . htmlspecialchars($token) . '">';
        });

        // Erweiterte String-Funktionen
        $factory->registerFunction('str_limit', function (string $value, int $limit = 100, string $end = '...'): string {
            return mb_strlen($value, 'UTF-8') > $limit
                ? mb_substr($value, 0, $limit, 'UTF-8') . $end
                : $value;
        });

        $factory->registerFunction('str_words', function (string $value, int $words = 100, string $end = '...'): string {
            $wordArray = explode(' ', $value);
            return count($wordArray) > $words
                ? implode(' ', array_slice($wordArray, 0, $words)) . $end
                : $value;
        });

        // Array-Hilfsfunktionen
        $factory->registerFunction('array_get', function (array $array, string $key, mixed $default = null): mixed {
            return $array[$key] ?? $default;
        });

        $factory->registerFunction('array_has', function (array $array, string $key): bool {
            return array_key_exists($key, $array);
        });

        // Conditional-Funktionen
        $factory->registerFunction('when', function (bool $condition, mixed $value, mixed $default = null): mixed {
            return $condition ? $value : $default;
        });

        $factory->registerFunction('unless', function (bool $condition, mixed $value, mixed $default = null): mixed {
            return !$condition ? $value : $default;
        });

        $logger->debug("Extended template functions registered");
    }

    /**
     * Ermittelt die Basis-URL der Anwendung
     */
    private function getBaseUrl(): string
    {
        if (!isset($_SERVER['HTTP_HOST'])) {
            return '';
        }

        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        return $protocol . '://' . $_SERVER['HTTP_HOST'];
    }

    /**
     * Plant Garbage Collection für den Cache
     */
    private function scheduleGarbageCollection(FilesystemTemplateCache $cache, array $config, LoggerInterface $logger): void
    {
        $probability = $config['gc_probability'] ?? 0.01;

        if (mt_rand(1, 10000) / 10000 <= $probability) {
            try {
                $maxAge = $config['gc_max_age'] ?? 86400; // 24 Stunden
                $deletedCount = $cache->gc($maxAge);

                if ($deletedCount > 0) {
                    $logger->info("Garbage collection completed", [
                        'deleted_files' => $deletedCount,
                        'max_age_hours' => $maxAge / 3600
                    ]);
                }
            } catch (Throwable $e) {
                $logger->error("Garbage collection failed: " . $e->getMessage());
            }
        }
    }
}