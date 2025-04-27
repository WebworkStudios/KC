<?php

/**
 * Logging-Konfiguration
 *
 * Diese Datei enthält die Konfiguration für das Logging-System
 */

return [
    // Logging aktivieren
    'enabled' => true,

    // Log-Verzeichnis
    'dir' => env('LOG_DIR', BASE_PATH . '/logs'),

    // Standard-Log-Level (emergency, alert, critical, error, warning, notice, info, debug)
    'level' => env('LOG_LEVEL', DEBUG ? 'debug' : 'info'),

    // Standardformat für Datum/Zeit in Logs
    'date_format' => 'Y-m-d H:i:s.v',

    // Log-Typen für verschiedene Umgebungen
    'types' => [
        'development' => ['file', 'console'],
        'testing' => ['file'],
        'production' => ['file', 'syslog'],
    ],

    // Standardmäßig verwendeter Log-Typ basierend auf Umgebung
    'default' => env('LOG_TYPE', null),

    // Konfiguration für verschiedene Log-Handler
    'handlers' => [
        'file' => [
            'filename' => env('LOG_FILENAME', 'app.log'),
            'max_files' => env('LOG_MAX_FILES', 5),
            'max_size' => env('LOG_MAX_SIZE', 10485760), // 10 MB
            'permission' => env('LOG_PERMISSION', 0664),
            'rotate' => env('LOG_ROTATE', true),
            'mode' => 'a', // a = append, w = überschreiben
        ],

        'syslog' => [
            'ident' => env('LOG_SYSLOG_IDENT', 'php-adr'),
            'facility' => LOG_USER,
        ],

        'console' => [
            'ansi_colors' => true,
            'level_colors' => [
                'emergency' => 'red',
                'alert' => 'red',
                'critical' => 'red',
                'error' => 'light_red',
                'warning' => 'yellow',
                'notice' => 'cyan',
                'info' => 'green',
                'debug' => 'light_gray',
            ],
        ],
    ],

    // Konfiguration für Log-Prozessoren
    'processors' => [
        // Kontextprozessor aktivieren
        'context' => true,

        // Standardkontext für alle Logs
        'extra_context' => [
            'app_name' => env('APP_NAME', 'PHP 8.4 ADR Framework'),
            'environment' => ENVIRONMENT,
        ],

        // Web-Request-Informationen hinzufügen
        'add_request_info' => true,

        // Debug-Backtraces hinzufügen
        'add_backtrace' => ENVIRONMENT === 'development',

        // Backtracing-Tiefe
        'backtrace_depth' => 3,
    ],

    // Speichern sensibler Daten in Logs
    'privacy' => [
        // Automatische Anonymisierung sensibler Daten aktivieren
        'anonymize' => env('LOG_ANONYMIZE', true),

        // Felder, die anonymisiert werden sollen
        'anonymize_fields' => [
            'password', 'password_hash', 'secret', 'token', 'api_key', 'credit_card',
            'ssn', 'social_security_number', 'sin', 'ein', 'cc', 'auth',
        ],

        // Maskierungsstrategie (partial, full, hash)
        'mask_strategy' => 'partial',
    ],
];