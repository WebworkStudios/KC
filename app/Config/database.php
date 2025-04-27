<?php


/**
 * Datenbank-Konfiguration
 *
 * Diese Datei enthält die Konfiguration für alle Datenbankverbindungen
 */

return [
    // Standard-Verbindung
    'default_connection' => env('DB_CONNECTION', 'main'),

    // Verfügbare Datenbankverbindungen
    'connections' => [
        'main' => [
            'driver' => 'mysql',
            'database' => env('DB_DATABASE', 'app'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => env('DB_PREFIX', ''),
            'strict' => true,
            'engine' => 'InnoDB',
            'timezone' => '+00:00',

            // Load-Balancing-Strategie: 'round_robin', 'random', 'least_connections'
            'load_balancing' => env('DB_LOAD_BALANCING', 'round_robin'),

            // Standard-Modus: 'read' oder 'write'
            'default_mode' => env('DB_DEFAULT_MODE', 'read'),

            // Single-Server-Konfiguration (wird verwendet, wenn keine Server definiert sind)
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', 3306),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),

            // Multi-Server-Konfiguration für Load-Balancing
            'servers' => [
                // Beispiel für eine Multi-Server-Konfiguration
                /*
                [
                    'name' => 'master',
                    'host' => env('DB_HOST_MASTER', 'localhost'),
                    'port' => env('DB_PORT_MASTER', 3306),
                    'username' => env('DB_USERNAME_MASTER', 'root'),
                    'password' => env('DB_PASSWORD_MASTER', ''),
                    'type' => 'primary'  // 'primary' (read+write), 'read', 'write'
                ],
                [
                    'name' => 'slave1',
                    'host' => env('DB_HOST_SLAVE1', 'localhost'),
                    'port' => env('DB_PORT_SLAVE1', 3306),
                    'username' => env('DB_USERNAME_SLAVE1', 'readonly'),
                    'password' => env('DB_PASSWORD_SLAVE1', ''),
                    'type' => 'read'
                ],
                */
            ]
        ],

        // Beispiel für eine zweite Verbindung
        'second_db' => [
            'driver' => 'mysql',
            'database' => env('DB2_DATABASE', 'second_db'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => env('DB2_PREFIX', ''),
            'strict' => true,
            'engine' => 'InnoDB',
            'timezone' => '+00:00',
            'host' => env('DB2_HOST', 'localhost'),
            'port' => env('DB2_PORT', 3306),
            'username' => env('DB2_USERNAME', 'root'),
            'password' => env('DB2_PASSWORD', ''),
        ],
    ],

    // Query-Builder-Einstellungen
    'query_builder' => [
        // Auto-Caching für Abfragen aktivieren
        'auto_cache' => env('DB_AUTO_CACHE', false),

        // Standard-TTL für gecachte Abfragen in Sekunden (1 Stunde)
        'cache_ttl' => env('DB_CACHE_TTL', 3600),

        // Automatische Anonymisierung aktivieren
        'auto_anonymize' => env('DB_AUTO_ANONYMIZE', false),

        // Felder, die automatisch anonymisiert werden sollen
        'anonymize_fields' => [
            'password' => 'null',
            'password_hash' => 'null',
            'email' => 'email',
            'credit_card' => 'credit_card',
            'address' => 'address',
            'phone' => 'phone',
            'ip_address' => 'ip',
        ],
    ],

    // Pool-Konfiguration
    'pool' => [
        'min_connections' => env('DB_POOL_MIN', 0),
        'max_connections' => env('DB_POOL_MAX', 10),
        'idle_timeout' => env('DB_POOL_IDLE', 60),
    ],
];