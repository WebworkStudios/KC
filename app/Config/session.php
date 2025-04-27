<?php

/**
 * Session-Konfiguration
 *
 * Diese Datei enthält die Konfiguration für das Session-Management und den CSRF-Schutz.
 */

return [
    // Session-Typ (php, redis)
    'type' => env('SESSION_DRIVER', 'php'),

    // Session-Cookie-Einstellungen
    'name' => env('SESSION_NAME', 'PHPSESSID'),
    'lifetime' => (int)env('SESSION_LIFETIME', 7200),  // 2 Stunden
    'path' => env('SESSION_PATH', '/'),
    'domain' => env('SESSION_DOMAIN', null),
    'secure' => (bool)env('SESSION_SECURE', false),
    'httponly' => (bool)env('SESSION_HTTPONLY', true),
    'samesite' => env('SESSION_SAMESITE', 'Lax'),

    // Namespace für Session-Variablen
    'namespace' => env('SESSION_NAMESPACE', 'app'),

    // Session-Einstellungen
    'autostart' => (bool)env('SESSION_AUTOSTART', false),
    'gc_maxlifetime' => (int)env('SESSION_GC_MAXLIFETIME', 7200),
    'gc_probability' => (int)env('SESSION_GC_PROBABILITY', 1),
    'gc_divisor' => (int)env('SESSION_GC_DIVISOR', 100),
    'lazy_write' => (bool)env('SESSION_LAZY_WRITE', true),
    'sid_length' => (int)env('SESSION_SID_LENGTH', 48),
    'sid_bits_per_character' => (int)env('SESSION_SID_BITS', 6),
    'strict_mode' => (bool)env('SESSION_STRICT_MODE', true),

    // Sicherheitseinstellungen
    'use_fingerprint' => (bool)env('SESSION_USE_FINGERPRINT', true),
    'inactivity_timeout' => (int)env('SESSION_INACTIVITY_TIMEOUT', 1800),  // 30 Minuten
    'absolute_timeout' => (int)env('SESSION_ABSOLUTE_TIMEOUT', 43200),    // 12 Stunden
    'regenerate_after' => (int)env('SESSION_REGENERATE_AFTER', 300),      // 5 Minuten

    // Redis-Einstellungen (wenn Redis-Session verwendet wird)
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int)env('REDIS_PORT', 6379),
        'timeout' => (float)env('REDIS_TIMEOUT', 0.0),
        'auth' => env('REDIS_PASSWORD', null),
        'database' => (int)env('REDIS_SESSION_DB', 0),
        'prefix' => env('REDIS_SESSION_PREFIX', 'session:'),
        'persistent' => (bool)env('REDIS_PERSISTENT', true),
        'autoconnect' => (bool)env('REDIS_AUTOCONNECT', true),
        'lock_ttl' => (int)env('REDIS_LOCK_TTL', 60),            // 60 Sekunden
        'lock_timeout' => (int)env('REDIS_LOCK_TIMEOUT', 10),    // 10 Sekunden
        'lock_wait' => (int)env('REDIS_LOCK_WAIT', 20000),       // 20ms
        'read_timeout' => (float)env('REDIS_READ_TIMEOUT', 0.0),
    ],

    // CSRF-Schutz-Einstellungen
    'csrf' => [
        'enabled' => (bool)env('CSRF_ENABLED', true),
        'token_name' => env('CSRF_TOKEN_NAME', '_csrf'),
        'token_header' => env('CSRF_TOKEN_HEADER', 'X-CSRF-Token'),
        'parameter_name' => env('CSRF_PARAMETER_NAME', '_csrf'),
        'lifetime' => (int)env('CSRF_LIFETIME', 3600),           // 1 Stunde
        'session_key' => env('CSRF_SESSION_KEY', '_csrf_tokens'),
        'https_only' => (bool)env('CSRF_HTTPS_ONLY', false),
        'secret' => env('CSRF_SECRET', null),
        'algorithm' => env('CSRF_ALGORITHM', 'sha256'),
        'exclude_methods' => ['GET', 'HEAD', 'OPTIONS'],
        'exclude_routes' => explode(',', env('CSRF_EXCLUDE_ROUTES', '/api/v1/webhook,/api/v1/callback')),
        'exclude_paths' => explode(',', env('CSRF_EXCLUDE_PATHS', '/api/v1/*,/webhook/*')),
        'error_message' => env('CSRF_ERROR_MESSAGE', 'CSRF-Token ungültig oder abgelaufen. Bitte versuchen Sie es erneut.'),
        'error_code' => (int)env('CSRF_ERROR_CODE', 403),
        'auto_cleaner' => (bool)env('CSRF_AUTO_CLEANER', true),
        'cleaner_chance' => (float)env('CSRF_CLEANER_CHANCE', 0.1),
        'auto_regenerate' => (bool)env('CSRF_AUTO_REGENERATE', true),
        'rotate_frequency' => (int)env('CSRF_ROTATE_FREQUENCY', 300),   // 5 Minuten
        'strict_mode' => (bool)env('CSRF_STRICT_MODE', true),
        'dynamic_tokens' => (bool)env('CSRF_DYNAMIC_TOKENS', true),
    ],
];