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
 * Vollständiger verbesserter ViewServiceProvider mit intelligenter Cache-Verwaltung
 */
class ViewServiceProvider
{
    /**
     * Registriert alle View-Services im Container
     */
    public function register(Container $container, array $config = []): void
    {
        $logger = $container->get(LoggerInterface::class);

        try {
            // Erweiterte Konfiguration mit Environment-spezifischen Defaults
            $config = $this->getCacheConfigForEnvironment($this->mergeDefaultConfig($config));

            $logger->debug("Configuring template engine with intelligent cache management", [
                'template_dir' => $config['template_dir'],
                'cache_dir' => $config['cache_dir'],
                'use_cache' => $config['use_cache'],
                'environment' => $config['app']['environment'] ?? 'unknown',
                'max_cache_size' => $config['max_cache_size'] ?? 1000
            ]);

            // Verzeichnisse sicherstellen
            $this->ensureDirectories($config, $logger);

            // Services in optimaler Reihenfolge registrieren
            $this->registerLoader($container, $config, $logger);
            $this->registerCompiler($container, $config, $logger);
            $this->registerCache($container, $config, $logger);  // ← Erweiterte Cache-Registrierung
            $this->registerEngine($container, $config, $logger);
            $this->registerFactory($container, $config, $logger);

            $logger->info("View services registered successfully with intelligent cache management");

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
            'enable_gc' => true,

            // Cache-spezifische Defaults
            'max_cache_size' => 1000,
            'gc_probability' => 0.01,
            'gc_max_age' => 86400,
            'optimize_probability' => 0.001,
            'compress_probability' => 0.005,
            'startup_optimizations' => true,
            'startup_gc_threshold' => 1000,
            'startup_gc_age' => 172800,
            'size_warning_threshold' => 200,
            'optimize_threshold' => 200
        ], $config);
    }

    /**
     * Erweiterte Cache-Registrierung mit intelligenter Wartung
     */
    private function registerCache(Container $container, array $config, LoggerInterface $logger): void
    {
        $container->register(FilesystemTemplateCache::class, function () use ($config, $logger) {
            $cache = new FilesystemTemplateCache(
                $config['cache_dir'],
                $config['use_cache']
            );

            // Performance-Konfiguration
            if (isset($config['max_cache_size'])) {
                $cache->setMaxCacheSize($config['max_cache_size']);
                $logger->debug("Cache max size set to: {$config['max_cache_size']}");
            }

            // Erweiterte Cache-Konfiguration aus Config
            if (isset($config['cache'])) {
                $cacheConfig = $config['cache'];

                // In-Memory Cache-Größe
                if (isset($cacheConfig['memory_cache_size'])) {
                    $cache->setMaxCacheSize($cacheConfig['memory_cache_size']);
                }

                // Cache aktivieren/deaktivieren zur Laufzeit
                if (isset($cacheConfig['runtime_toggle'])) {
                    $cache->setEnabled($cacheConfig['runtime_toggle']);
                }
            }

            // Automatische Wartung konfigurieren
            if ($config['enable_gc'] && $config['use_cache']) {
                $this->configureAutomaticMaintenance($cache, $config, $logger);
            }

            // Startup-Optimierungen
            if (isset($config['startup_optimizations']) && $config['startup_optimizations']) {
                $this->performStartupOptimizations($cache, $config, $logger);
            }

            // Monitoring aktivieren (nur in Debug-Modus)
            if ($config['debug_mode']) {
                $this->enableCacheMonitoring($cache, $logger);
            }

            $logger->debug("Template cache registered and configured", [
                'cache_dir' => $config['cache_dir'],
                'enabled' => $config['use_cache'],
                'max_size' => $config['max_cache_size'] ?? 1000,
                'gc_enabled' => $config['enable_gc'] ?? false,
                'environment_optimized' => true
            ]);

            return $cache;
        });
    }

    /**
     * Konfiguriert automatische Cache-Wartung
     */
    private function configureAutomaticMaintenance(
        FilesystemTemplateCache $cache,
        array $config,
        LoggerInterface $logger
    ): void {
        // Garbage Collection mit konfigurierbarer Wahrscheinlichkeit
        $gcProbability = $config['gc_probability'] ?? 0.01;
        $gcMaxAge = $config['gc_max_age'] ?? 86400;

        if (mt_rand(1, 10000) / 10000 <= $gcProbability) {
            try {
                $startTime = microtime(true);
                $deletedFiles = $cache->gc($gcMaxAge);
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                if ($deletedFiles > 0) {
                    $logger->info("Automatic garbage collection completed", [
                        'deleted_files' => $deletedFiles,
                        'max_age_hours' => $gcMaxAge / 3600,
                        'duration_ms' => $duration
                    ]);
                } else {
                    $logger->debug("Garbage collection ran - no files to delete", [
                        'duration_ms' => $duration
                    ]);
                }
            } catch (Throwable $e) {
                $logger->error("Automatic garbage collection failed: " . $e->getMessage(), [
                    'exception' => get_class($e)
                ]);
            }
        }

        // Cache-Optimierung (seltener, nur bei größeren Caches)
        $optimizeProbability = $config['optimize_probability'] ?? 0.001;
        if (mt_rand(1, 100000) / 100000 <= $optimizeProbability) {
            try {
                $stats = $cache->getStats();

                // Nur optimieren wenn Cache groß genug ist
                if ($stats['total_files'] > ($config['optimize_threshold'] ?? 200)) {
                    $startTime = microtime(true);
                    $success = $cache->optimize();
                    $duration = round((microtime(true) - $startTime) * 1000, 2);

                    if ($success) {
                        $logger->info("Automatic cache optimization completed", [
                            'total_files_before' => $stats['total_files'],
                            'duration_ms' => $duration
                        ]);
                    }
                }
            } catch (Throwable $e) {
                $logger->error("Automatic cache optimization failed: " . $e->getMessage());
            }
        }

        // Komprimierung (entfernt Duplikate)
        $compressProbability = $config['compress_probability'] ?? 0.005;
        if (mt_rand(1, 10000) / 10000 <= $compressProbability) {
            try {
                $startTime = microtime(true);
                $removedDuplicates = $cache->compress();
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                if ($removedDuplicates > 0) {
                    $logger->info("Automatic cache compression completed", [
                        'removed_duplicates' => $removedDuplicates,
                        'duration_ms' => $duration
                    ]);
                }
            } catch (Throwable $e) {
                $logger->error("Automatic cache compression failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Führt Startup-Optimierungen durch
     */
    private function performStartupOptimizations(
        FilesystemTemplateCache $cache,
        array $config,
        LoggerInterface $logger
    ): void {
        try {
            $stats = $cache->getStats();
            $logger->debug("Cache startup statistics", $stats);

            // Cache-Gesundheitscheck
            if (!$stats['directory_writable']) {
                $logger->error("Cache directory is not writable", [
                    'cache_dir' => $stats['cache_dir']
                ]);
                return;
            }

            // Große Cache-Größe warnen
            if ($stats['total_size_mb'] > ($config['size_warning_threshold'] ?? 200)) {
                $logger->warning("Cache size is large", [
                    'size_mb' => $stats['total_size_mb'],
                    'total_files' => $stats['total_files'],
                    'suggestion' => 'Consider running garbage collection'
                ]);
            }

            // Niedrige Hit-Ratio bei vorhandenen Cache-Zugriffen
            if ($stats['access_count'] > 100 && $stats['hit_ratio'] < 70) {
                $logger->warning("Low cache hit ratio detected", [
                    'hit_ratio' => $stats['hit_ratio'],
                    'access_count' => $stats['access_count'],
                    'suggestion' => 'Consider increasing max_cache_size'
                ]);
            }

            // Startup-GC bei sehr vielen Dateien
            if ($stats['total_files'] > ($config['startup_gc_threshold'] ?? 1000)) {
                $logger->info("Running startup garbage collection", [
                    'total_files' => $stats['total_files']
                ]);

                $deletedFiles = $cache->gc($config['startup_gc_age'] ?? 172800);
                if ($deletedFiles > 0) {
                    $logger->info("Startup GC completed", [
                        'deleted_files' => $deletedFiles
                    ]);
                }
            }

        } catch (Throwable $e) {
            $logger->error("Startup optimizations failed: " . $e->getMessage());
        }
    }

    /**
     * Aktiviert Cache-Monitoring für Debug-Modus
     */
    private function enableCacheMonitoring(
        FilesystemTemplateCache $cache,
        LoggerInterface $logger
    ): void {
        // Registriere Shutdown-Handler für finale Statistiken
        register_shutdown_function(function() use ($cache, $logger) {
            try {
                $finalStats = $cache->getStats();

                $logger->debug("Final cache statistics", [
                    'total_accesses' => $finalStats['access_count'],
                    'hit_ratio' => $finalStats['hit_ratio'],
                    'memory_cache_entries' => $finalStats['memory_cache_entries'],
                    'total_files' => $finalStats['total_files'],
                    'total_size_mb' => $finalStats['total_size_mb']
                ]);

                // Performance-Warnungen
                if ($finalStats['hit_ratio'] < 50 && $finalStats['access_count'] > 50) {
                    $logger->warning("Poor cache performance detected", [
                        'hit_ratio' => $finalStats['hit_ratio'],
                        'recommendation' => 'Increase max_cache_size or check template patterns'
                    ]);
                }

            } catch (Throwable $e) {
                // Ignore monitoring errors during shutdown
            }
        });
    }

    /**
     * Environment-spezifische Cache-Konfiguration
     */
    private function getCacheConfigForEnvironment(array $baseConfig): array
    {
        $environment = $baseConfig['app']['environment'] ?? 'production';

        $envDefaults = match($environment) {
            'development' => [
                'max_cache_size' => 500,           // Kleinerer Cache in Dev
                'gc_probability' => 0.1,           // Häufigere GC in Dev
                'gc_max_age' => 3600,             // 1h in Dev
                'optimize_probability' => 0.01,    // Häufigere Optimierung
                'startup_optimizations' => true,
                'size_warning_threshold' => 50     // Frühere Warnung in Dev
            ],
            'testing' => [
                'max_cache_size' => 100,           // Sehr kleiner Cache für Tests
                'gc_probability' => 0.5,           // Sehr häufige GC
                'gc_max_age' => 300,              // 5min für Tests
                'optimize_probability' => 0.1,
                'startup_optimizations' => false,
                'enable_gc' => false              // Meist kein GC in Tests nötig
            ],
            'staging' => [
                'max_cache_size' => 1000,
                'gc_probability' => 0.05,          // Häufiger als Production
                'gc_max_age' => 43200,            // 12h
                'optimize_probability' => 0.005,
                'startup_optimizations' => true,
                'size_warning_threshold' => 100
            ],
            'production' => [
                'max_cache_size' => 2000,          // Großer Cache in Production
                'gc_probability' => 0.01,          // Seltene GC
                'gc_max_age' => 86400,            // 24h
                'optimize_probability' => 0.001,   // Seltene Optimierung
                'compress_probability' => 0.005,   // Gelegentliche Komprimierung
                'startup_optimizations' => true,
                'startup_gc_threshold' => 2000,
                'size_warning_threshold' => 500
            ],
            default => []
        };

        return array_merge($baseConfig, $envDefaults);
    }

    // Weitere Methoden (ensureDirectories, registerLoader, etc.) bleiben unverändert...

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

    private function registerCompiler(Container $container, array $config, LoggerInterface $logger): void
    {
        $container->register(TemplateCompiler::class, function () use ($config, $logger) {
            $compiler = new TemplateCompiler();

            if (isset($config['compiler']['custom_passes'])) {
                foreach ($config['compiler']['custom_passes'] as $passClass) {
                    if (class_exists($passClass)) {
                        $compiler->registerPass(new $passClass());
                        $logger->debug("Custom compiler pass registered: {$passClass}");
                    }
                }
            }

            $logger->debug("Template compiler registered");
            return $compiler;
        });
    }

    private function registerEngine(Container $container, array $config, LoggerInterface $logger): void
    {
        $container->register(TemplateEngine::class, function () use ($container, $config, $logger) {
            $loader = $container->get(FilesystemTemplateLoader::class);
            $compiler = $container->get(TemplateCompiler::class);
            $cache = $container->get(FilesystemTemplateCache::class);

            // KORRIGIERTE Parameter-Reihenfolge: loader, compiler, cache
            $engine = new TemplateEngine($loader, $compiler, $cache);

            if ($config['debug_mode']) {
                $engine->setDebugMode(true);
            }

            $logger->debug("Template engine registered with corrected parameter order");
            return $engine;
        });
    }

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

            // Erweiterte Template-Funktionen registrieren
            $this->registerExtendedTemplateFunctions($factory, $logger);

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
            'current_timestamp' => time(),
            'cache_enabled' => $config['use_cache'] ?? true,
            'template_engine_version' => '2.0.0' // Version der verbesserten Engine
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

        $factory->registerFunction('csrf_field', function () use ($factory): string {
            $token = $factory->getEngine()->callFunction('csrf_token', []);
            return '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        });

        // HTTP-Method-Spoofing für Formulare
        $factory->registerFunction('method_field', function (string $method): string {
            $method = strtoupper($method);
            if (!in_array($method, ['PUT', 'PATCH', 'DELETE'])) {
                throw new \InvalidArgumentException("Invalid HTTP method for spoofing: {$method}");
            }
            return '<input type="hidden" name="_method" value="' . $method . '">';
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

        $factory->registerFunction('str_slug', function (string $value, string $separator = '-'): string {
            // Deutsche Umlaute ersetzen
            $replacements = [
                'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
                'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue'
            ];
            $value = strtr($value, $replacements);

            // Zu lowercase und nicht-alphanumerische Zeichen ersetzen
            $value = strtolower($value);
            $value = preg_replace('/[^a-z0-9]+/', $separator, $value);
            return trim($value, $separator);
        });

        // Array-Hilfsfunktionen
        $factory->registerFunction('array_get', function (array $array, string $key, mixed $default = null): mixed {
            return $array[$key] ?? $default;
        });

        $factory->registerFunction('array_has', function (array $array, string $key): bool {
            return array_key_exists($key, $array);
        });

        $factory->registerFunction('array_first', function (array $array, mixed $default = null): mixed {
            return empty($array) ? $default : reset($array);
        });

        $factory->registerFunction('array_last', function (array $array, mixed $default = null): mixed {
            return empty($array) ? $default : end($array);
        });

        // Conditional-Funktionen
        $factory->registerFunction('when', function (bool $condition, mixed $value, mixed $default = null): mixed {
            return $condition ? $value : $default;
        });

        $factory->registerFunction('unless', function (bool $condition, mixed $value, mixed $default = null): mixed {
            return !$condition ? $value : $default;
        });

        // Datum und Zeit
        $factory->registerFunction('now', function (string $format = 'Y-m-d H:i:s'): string {
            return date($format);
        });

        $factory->registerFunction('carbon', function (mixed $date = null): \DateTime {
            if ($date === null) {
                return new \DateTime();
            }

            if ($date instanceof \DateTime) {
                return $date;
            }

            if (is_string($date)) {
                return new \DateTime($date);
            }

            if (is_numeric($date)) {
                $dateTime = new \DateTime();
                $dateTime->setTimestamp((int)$date);
                return $dateTime;
            }

            throw new \InvalidArgumentException('Invalid date format');
        });

        // Formatierung
        $factory->registerFunction('money', function (float $amount, string $currency = 'EUR', string $locale = 'de_DE'): string {
            if (class_exists('NumberFormatter')) {
                $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
                return $formatter->formatCurrency($amount, $currency);
            }

            // Fallback ohne Intl-Extension
            return number_format($amount, 2, ',', '.') . ' ' . $currency;
        });

        $factory->registerFunction('percent', function (float $value, int $decimals = 1): string {
            return number_format($value * 100, $decimals, ',', '.') . '%';
        });

        // HTML-Hilfsfunktionen
        $factory->registerFunction('link_to', function (string $url, string $text, array $attributes = []): string {
            $attrs = '';
            foreach ($attributes as $key => $value) {
                $attrs .= ' ' . $key . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
            }
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $attrs . '>' .
                htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
        });

        $factory->registerFunction('image', function (string $src, string $alt = '', array $attributes = []): string {
            $attrs = 'src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' .
                htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"';

            foreach ($attributes as $key => $value) {
                $attrs .= ' ' . $key . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
            }

            return '<img ' . $attrs . '>';
        });

        // Debug-Funktionen (nur in Debug-Modus)
        $factory->registerFunction('dd', function (mixed ...$args): never {
            echo '<pre style="background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; margin: 10px 0;">';
            foreach ($args as $arg) {
                var_dump($arg);
            }
            echo '</pre>';
            die();
        });

        $factory->registerFunction('dump', function (mixed $var): string {
            ob_start();
            var_dump($var);
            $output = ob_get_clean();
            return '<pre style="background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6; font-size: 12px;">' .
                htmlspecialchars($output, ENT_QUOTES, 'UTF-8') . '</pre>';
        });

        // Collection-Hilfsfunktionen
        $factory->registerFunction('collect', function (array $items): array {
            return $items;
        });

        $factory->registerFunction('pluck', function (array $items, string $key): array {
            return array_column($items, $key);
        });

        $factory->registerFunction('group_by', function (array $items, string $key): array {
            $grouped = [];
            foreach ($items as $item) {
                $groupKey = is_array($item) ? $item[$key] ?? 'undefined' : $item->$key ?? 'undefined';
                $grouped[$groupKey][] = $item;
            }
            return $grouped;
        });

        // Cache-Funktionen (für Template-Level-Caching)
        $factory->registerFunction('cache_key', function (string ...$parts): string {
            return 'template_' . md5(implode('_', $parts));
        });

        $logger->debug("Extended template functions registered", [
            'function_count' => 25 // Anzahl der registrierten Funktionen
        ]);
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
}