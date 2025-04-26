<?php


/**
 * Cache-Konfiguration
 *
 * Diese Datei enthält die Konfiguration für das Caching-System.
 */

return [
    // Standard-Cache-Typ
    'default' => env('CACHE_DRIVER', 'file'),

    // Präfix für alle Cache-Schlüssel
    'prefix' => env('CACHE_PREFIX', 'app'),

    // TTL in Sekunden (Standard: 1 Stunde)
    'ttl' => env('CACHE_TTL', 3600),

    // Verfügbare Cache-Backends
    'backends' => [
        'file' => [
            'dir' => env('CACHE_FILE_DIR', dirname(__DIR__, 2) . '/cache'),
            'permissions' => [
                'directory' => 0775,
                'file' => 0664,
            ],
            'deep_directory' => true,
            // Intervall für Garbage Collection in Sekunden (Standard: 1 Stunde)
            'gc_interval' => env('CACHE_FILE_GC_INTERVAL', 3600),
            // Wahrscheinlichkeit für Garbage Collection (0-100)
            'gc_probability' => env('CACHE_FILE_GC_PROBABILITY', 10),
        ],
        'redis' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'timeout' => env('REDIS_TIMEOUT', 0.0),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_CACHE_DB', 1),
            'persistent' => env('REDIS_PERSISTENT', true),
        ],
    ],

    // HTTP-Caching-Konfiguration
    'http' => [
        // HTTP-Caching aktivieren
        'enabled' => env('CACHE_HTTP_ENABLED', true),
        // TTL für HTTP-Caching in Sekunden (Standard: 5 Minuten)
        'ttl' => env('CACHE_HTTP_TTL', 300),
        // Query-Parameter beim Caching berücksichtigen
        'use_query_params' => env('CACHE_HTTP_USE_QUERY_PARAMS', true),
        // Pfade, die nicht gecacht werden sollen
        'exclude_paths' => [
            '/api/v1/status',
            '/admin/*',
        ],
    ],
];