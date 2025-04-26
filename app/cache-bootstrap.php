<?php

/**
 * Cache-Bootstrapping für die Anwendung
 *
 * Initialisiert den Cache und registriert ihn im Container
 */

use Src\Cache\CacheFactory;
use Src\Cache\CacheInterface;
use Src\Cache\FileCache;
use Src\Container\Container;
use Src\Http\Middleware\CacheMiddleware;
use Src\Log\LoggerInterface;

/**
 * Cache initialisieren und im Container registrieren
 *
 * @param Container $container DI-Container
 * @param array $config Anwendungskonfiguration
 * @return void
 */
function bootstrapCache(Src\Container\Container $container, array $config): void
{
    // Logger abrufen
    $logger = $container->get(LoggerInterface::class);

    // Cache-Factory erstellen und registrieren
    $cacheFactory = new CacheFactory($logger);
    $container->register(CacheFactory::class, $cacheFactory);

    // Cache-Konfiguration
    $cacheConfig = $config['cache'] ?? include __DIR__ . '/Config/cache.php';

    // Cache-Typ bestimmen
    $cacheType = $cacheConfig['default'] ?? 'file';
    $environment = $config['app']['environment'] ?? 'development';

    try {
        // Cache-Instanz erstellen
        if ($cacheType === 'default') {
            $cache = $cacheFactory->createDefaultCache($environment, $cacheConfig);
        } else {
            $cache = $cacheFactory->createCache($cacheType, $cacheConfig);
        }

        // Cache im Container registrieren
        $container->register(CacheInterface::class, $cache);

        $logger->info("Cache initialisiert", [
            'type' => get_class($cache),
            'environment' => $environment
        ]);

        // Garbage Collection für FileCache
        if ($cache instanceof FileCache) {
            $gcInterval = $cacheConfig['backends']['file']['gc_interval'] ?? 3600;
            $gcProbability = $cacheConfig['backends']['file']['gc_probability'] ?? 10;

            // Garbage Collection mit konfigurierbarer Wahrscheinlichkeit ausführen
            if (mt_rand(1, 100) <= $gcProbability) {
                $deletedEntries = $cache->gc();
                $logger->info("Cache Garbage Collection ausgeführt", [
                    'deleted_entries' => $deletedEntries
                ]);
            }
        }

        // HTTP Cache-Middleware registrieren, falls aktiviert
        if (($cacheConfig['http']['enabled'] ?? false) === true) {
            $cacheMiddleware = new CacheMiddleware(
                $cache,
                $logger,
                $cacheConfig['http']['ttl'] ?? 300,
                $cacheConfig['http']['use_query_params'] ?? true
            );

            $container->register(CacheMiddleware::class, $cacheMiddleware);

            $logger->info("HTTP Cache-Middleware registriert", [
                'ttl' => $cacheConfig['http']['ttl'] ?? 300
            ]);
        }

    } catch (\Throwable $e) {
        // Bei Fehlern Warnung protokollieren und Null-Cache verwenden
        $logger->error("Fehler beim Initialisieren des Caches: " . $e->getMessage(), [
            'exception' => get_class($e),
            'trace' => $e->getTraceAsString()
        ]);

        // Fallback auf Null-Cache
        $nullCache = $cacheFactory->createCache('null');
        $container->register(CacheInterface::class, $nullCache);

        $logger->warning("Fallback auf Null-Cache");
    }
}