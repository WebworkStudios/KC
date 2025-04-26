<?php

/**
 * Redis-Konfiguration
 *
 * Diese Datei enthält die Konfiguration für Redis-Verbindungen,
 * die vom Framework verwendet werden.
 */

return [
    // Standard-Redis-Verbindung
    'default' => env('REDIS_CONNECTION', 'default'),

    // Verfügbare Redis-Verbindungen
    'connections' => [
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DB', 0),
            'timeout' => env('REDIS_TIMEOUT', 0.0),
            'persistent' => env('REDIS_PERSISTENT', true),
            'prefix' => env('REDIS_PREFIX', 'app:'),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 0.0),
        ],

        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_CACHE_DB', 1),
            'timeout' => env('REDIS_TIMEOUT', 0.0),
            'persistent' => env('REDIS_PERSISTENT', true),
            'prefix' => env('REDIS_CACHE_PREFIX', 'cache:'),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 0.0),
        ],

        'database' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DB_CACHE_DB', 2),
            'timeout' => env('REDIS_TIMEOUT', 0.0),
            'persistent' => env('REDIS_PERSISTENT', true),
            'prefix' => env('REDIS_DB_CACHE_PREFIX', 'db_cache:'),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 0.0),
        ],

        'sessions' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_SESSION_DB', 3),
            'timeout' => env('REDIS_TIMEOUT', 0.0),
            'persistent' => env('REDIS_PERSISTENT', true),
            'prefix' => env('REDIS_SESSION_PREFIX', 'session:'),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 0.0),
        ],
    ],

    // Einstellungen für das Clustering (Redis-Cluster)
    'cluster' => [
        'enabled' => env('REDIS_CLUSTER_ENABLED', false),
        'nodes' => array_map('trim', explode(',', env('REDIS_CLUSTER_NODES', '127.0.0.1:6379'))),
        'options' => [
            'cluster' => env('REDIS_CLUSTER_OPTIONS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_CLUSTER_OPTIONS_PREFIX', 'app:'),
            'read_timeout' => env('REDIS_CLUSTER_OPTIONS_READ_TIMEOUT', 0.0),
            'timeout' => env('REDIS_CLUSTER_OPTIONS_TIMEOUT', 0.0),
        ],
    ],

    // Einstellungen für das automatische Cache-Löschen
    'gc' => [
        'probability' => env('REDIS_GC_PROBABILITY', 1), // 1% Wahrscheinlichkeit
        'divisor' => env('REDIS_GC_DIVISOR', 100),
    ],

    // Einstellungen für die Persistenz
    'persistence' => [
        // AOF (Append-Only File) - Sicherer, aber langsamer
        'aof' => [
            'enabled' => env('REDIS_AOF_ENABLED', true),
            'fsync' => env('REDIS_AOF_FSYNC', 'everysec'), // 'always', 'everysec', 'no'
        ],

        // RDB (Redis Database) - Schneller, aber mit Datenverlustrisiko
        'rdb' => [
            'enabled' => env('REDIS_RDB_ENABLED', true),
            'save' => [
                // Format: Sekunden => Änderungen
                ['900', '1'],    // Speichern wenn 1 Änderung in 15 Minuten
                ['300', '10'],   // Speichern wenn 10 Änderungen in 5 Minuten
                ['60', '10000'], // Speichern wenn 10000 Änderungen in 1 Minute
            ],
        ],
    ],

    // Einstellungen für die Performance-Optimierung
    'performance' => [
        'max_memory' => env('REDIS_MAX_MEMORY', '128mb'),
        'max_memory_policy' => env('REDIS_MAX_MEMORY_POLICY', 'allkeys-lru'),
        'lazyfree_lazy_eviction' => env('REDIS_LAZYFREE_LAZY_EVICTION', 'yes'),
        'lazyfree_lazy_expire' => env('REDIS_LAZYFREE_LAZY_EXPIRE', 'yes'),
    ],
];