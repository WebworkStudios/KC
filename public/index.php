<?php

// Define project root path
define('BASE_PATH', dirname(__FILE__));

// Load Composer autoloader
require BASE_PATH . '/../vendor/autoload.php';

// Initialize Container
$container = new Src\Container\Container();

// Register Logger
$logger = new Src\Log\FileLogger(BASE_PATH . '/logs/app.log', 'debug');
$container->register(Src\Log\LoggerInterface::class, $logger);

// Load Configuration
$config = new Src\Config([
    'app' => [
        'name' => 'PHP 8.4 ADR Framework Demo',
        'debug' => true,
        'environment' => 'development'
    ],
    'database' => [
        'connections' => [
            'kickerscup' => [
                'database' => 'kickerscup',
                'servers' => [
                    [
                        'name' => 'primary',
                        'host' => 'localhost',
                        'username' => 'root',
                        'password' => '',
                        'port' => 3306,
                        'type' => 'primary'
                    ]
                ]
            ],
            'forum' => [
                'database' => 'forum',
                'servers' => [
                    [
                        'name' => 'primary',
                        'host' => 'localhost',
                        'username' => 'root',
                        'password' => '',
                        'port' => 3306,
                        'type' => 'primary'
                    ]
                ]
            ]
        ]
    ]
]);

$container->register(Src\Config::class, $config);

// Configure Database Connections
// Configure 'kickerscup' connection
Src\Database\DatabaseFactory::configureConnection(
    name: 'kickerscup',
    database: $config->get('database.connections.kickerscup.database'),
    servers: $config->get('database.connections.kickerscup.servers'),
    loadBalancingStrategy: Src\Database\LoadBalancingStrategy::ROUND_ROBIN,
    defaultMode: Src\Database\Enums\ConnectionMode::READ,
    logger: $logger
);

// Configure 'forum' connection
Src\Database\DatabaseFactory::configureConnection(
    name: 'forum',
    database: $config->get('database.connections.forum.database'),
    servers: $config->get('database.connections.forum.servers'),
    loadBalancingStrategy: Src\Database\LoadBalancingStrategy::ROUND_ROBIN,
    defaultMode: Src\Database\Enums\ConnectionMode::READ,
    logger: $logger
);

// Set up Cache for QueryBuilder
$cache = Src\Database\DatabaseFactory::createArrayCache('default_cache');

// Register Router - will be auto-detected
$router = new Src\Http\Router($container, $logger);
$container->register(Src\Http\Router::class, $router);

// Set up View Engine and register the View Service Provider
// use_cache auf false setzen für Entwicklung
$viewServiceProvider = new Src\View\ViewServiceProvider();
$viewServiceProvider->register($container, array_merge($config->all(), [
    'use_cache' => false
]));

// Cache leeren
$viewFactory = $container->get(Src\View\ViewFactory::class);
$viewFactory->clearCache();

// Create a Request from global variables
$request = Src\Http\Request::fromGlobals();

// Set up session
$sessionFactory = new Src\Session\SessionFactory($logger);
$session = $sessionFactory->createDefaultSession('development');
$container->register(Src\Session\SessionInterface::class, $session);

// Set up CSRF protection
$csrfTokenGenerator = new Src\Security\CsrfTokenGenerator();
$csrfTokenManager = new Src\Security\CsrfTokenManager($session, $csrfTokenGenerator, $logger);
$container->register(Src\Security\CsrfTokenManager::class, $csrfTokenManager);

// Register Routes from Action directory
$router->registerActionsFromDirectory('App\\Actions', BASE_PATH . '/../app/Actions');

try {
    // Dispatch the request to the appropriate action
    $response = $router->dispatch($request);

    // If no route was found, show a 404 page
    if ($response === null) {
        $response = Src\Http\Response::notFound('Page not found.');
    }

    // Send the response to the client
    $response->send();
} catch (Throwable $e) {
    // Log the error
    $logger->error('Application error: ' . $e->getMessage(), [
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    // Show a user-friendly error page
    if ($config->get('app.debug')) {
        // Show detailed error information in debug mode
        $errorPage = "<h1>Application Error</h1>";
        $errorPage .= "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        $errorPage .= "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . " on line " . $e->getLine() . "</p>";
        $errorPage .= "<h2>Stack Trace:</h2>";
        $errorPage .= "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } else {
        // Show a generic error page in production
        $errorPage = "<h1>Application Error</h1>";
        $errorPage .= "<p>An error occurred while processing your request. Please try again later.</p>";
    }

    $response = new Src\Http\Response($errorPage, 500, 'text/html; charset=UTF-8');
    $response->send();
}

// Close database connections at the end of the request
Src\Database\DatabaseFactory::closeConnections();