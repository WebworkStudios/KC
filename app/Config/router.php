<?php


/**
 * Router-Konfiguration
 *
 * Diese Datei enthält die Konfiguration für den Router
 */

return [
    // Verzeichnis mit Action-Klassen
    'action_dir' => BASE_PATH . '/app/Actions',

    // Namespace für Action-Klassen
    'action_namespace' => 'App\\Actions',

    // Methoden-Name für die Hauptmethode in Action-Klassen
    'action_method' => '__invoke',

    // Aktiviere Auto-Discovery von Routes in Action-Klassen
    'auto_discover_routes' => true,

    // Standard-Werte für Routes
    'defaults' => [
        'methods' => ['GET'],  // Standard-HTTP-Methoden für Routes
        'middleware' => [],    // Standard-Middleware für alle Routes
    ],

    // 404-Fehlerseite
    'not_found' => [
        'action' => 'App\\Actions\\ErrorAction::notFound',
        'view' => 'errors/404',
    ],

    // 405-Fehlerseite (Method Not Allowed)
    'method_not_allowed' => [
        'action' => 'App\\Actions\\ErrorAction::methodNotAllowed',
        'view' => 'errors/405',
    ],

    // Fehlerseite für Serverprobleme
    'server_error' => [
        'action' => 'App\\Actions\\ErrorAction::serverError',
        'view' => 'errors/500',
    ],

    // Globale Middleware (wird für alle Routen angewendet)
    'global_middleware' => [
        'Src\\Http\\Middleware\\LoggingMiddleware',
    ],

    // Middleware-Gruppen
    'middleware_groups' => [
        'web' => [
            'Src\\Http\\Middleware\\CsrfMiddleware',
            'Src\\Http\\Middleware\\SessionMiddleware',
        ],
        'api' => [
        ],
        'cache' => [
            'Src\\Http\\Middleware\\CacheMiddleware',
        ],
    ],

    // Route-Präfixe mit zugeordneten Middleware-Gruppen
    'route_groups' => [
        'api' => [
            'prefix' => '/api',
            'middleware' => ['api'],
        ],
        'admin' => [
            'prefix' => '/admin',
            'middleware' => ['web', 'auth'],
        ],
    ],

    // Cache-Einstellungen für Routes
    'cache' => [
        'enabled' => env('ROUTE_CACHE_ENABLED', false),
        'file' => BASE_PATH . '/bootstrap/cache/routes.php',
    ],
];