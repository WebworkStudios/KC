<?php
namespace Src;

use App\Core\Database\DatabaseManager;
use App\Core\Database\QueryBuilderFactory;
use Src\Config\AppConfig;
use Src\Config\Container;
use Src\Http\Request;
use Src\Http\Response;
use Src\Routing\Router;
use Src\Security\CsrfProtection;
use Src\Security\Session;
use Src\Utils\Logger;

class Application
{
    /**
     * Singleton-Instanz
     */
    private static ?self $instance = null;

    private Container $container;
    private AppConfig $config;
    private Logger $logger;
    private Router $router;
    private ?DatabaseManager $dbManager = null;

    /**
     * Application als Singleton implementieren
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Create a new Application instance
     */
    private function __construct()
    {
        // Initialize container
        $this->container = new Container();

        // Register config instance
        $this->config = new AppConfig();
        $this->container->instance(AppConfig::class, $this->config);

        // Load configuration
        $this->loadConfiguration();

        // Register logger
        $this->logger = new Logger($this->config);
        $this->container->instance(Logger::class, $this->logger);

        // Register session
        $secure = $this->config->get('session.secure', true);
        $httpOnly = $this->config->get('session.httpOnly', true);
        $sameSite = $this->config->get('session.sameSite', 'Lax');

        $session = new Session($secure, $httpOnly, $sameSite);
        $this->container->instance(Session::class, $session);

        // Register CSRF protection
        $tokenName = $this->config->get('csrf.token_name', 'csrf_token');
        $tokenExpiration = $this->config->get('csrf.token_expiration', 7200);

        $csrfProtection = new CsrfProtection($session, $tokenName, $tokenExpiration);
        $this->container->instance(CsrfProtection::class, $csrfProtection);

        // Register router
        $this->router = new Router($this->config);
        $this->container->instance(Router::class, $this->router);

        // Register request
        $this->container->singleton(Request::class, function () {
            return Request::createFromGlobals();
        });

        // Initialize database if configured
        $this->initializeDatabase();

        // Bootstrap the application
        $this->bootstrap();
    }

    /**
     * Initialize database connections
     */
    private function initializeDatabase(): void
    {
        $dbConfig = $this->config->get('database', []);

        if (!empty($dbConfig)) {
            // Create database manager
            $this->dbManager = new DatabaseManager($dbConfig, $this->config->get('database.default', 'default'));
            $this->container->instance(DatabaseManager::class, $this->dbManager);

            // Create query builder factory
            $queryBuilderFactory = new QueryBuilderFactory($this->dbManager);
            $this->container->instance(QueryBuilderFactory::class, $queryBuilderFactory);
        }
    }

    /**
     * Load configuration files
     */
    private function loadConfiguration(): void
    {
        $configPath = dirname(__DIR__) . '/config';

        if (is_dir($configPath)) {
            $this->config->loadFromDirectory($configPath);
        }

        // Load environment-specific configuration
        $env = getenv('APP_ENV') ?: 'production';
        $envConfigPath = $configPath . '/' . $env;

        if (is_dir($envConfigPath)) {
            $this->config->loadFromDirectory($envConfigPath);
        }
    }

    /**
     * Bootstrap the application
     */
    private function bootstrap(): void
    {
        // Register routes
        $this->registerRoutes();

        // Register error handlers
        $this->registerErrorHandlers();

        // Run bootstrap files if they exist
        $bootstrapPath = dirname(__DIR__) . '/bootstrap';
        if (is_dir($bootstrapPath)) {
            foreach (glob("$bootstrapPath/*.php") as $file) {
                require $file;
            }
        }
    }

    /**
     * Register application routes
     */
    private function registerRoutes(): void
    {
        $routesFile = dirname(__DIR__) . '/app/routes.php';

        if (file_exists($routesFile)) {
            require $routesFile;
        }
    }

    /**
     * Register error handlers
     */
    private function registerErrorHandlers(): void
    {
        // Set global error handler
        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                // This error code is not included in error_reporting
                return;
            }

            $this->logger->error('PHP Error', [
                'message' => $message,
                'file' => $file,
                'line' => $line,
                'severity' => $severity
            ]);

            return true;
        });

        // Set exception handler
        set_exception_handler(function (\Throwable $exception) {
            $this->logger->error('Unhandled Exception', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]);

            $response = new Response(
                'An error occurred: ' . $exception->getMessage(),
                500
            );

            $response->send();
        });

        // Register shutdown function to catch fatal errors
        register_shutdown_function(function () {
            $error = error_get_last();

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $this->logger->critical('Fatal Error', [
                    'message' => $error['message'],
                    'file' => $error['file'],
                    'line' => $error['line']
                ]);

                // Don't attempt to create a new response if headers already sent
                if (!headers_sent()) {
                    (new Response('A fatal error occurred.', 500))->send();
                }
            }
        });
    }

    /**
     * Handle the current request
     */
    public function handle(): void
    {
        try {
            // Get the current request
            $request = $this->container->get(Request::class);

            // Validate CSRF token for unsafe methods
            if (in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH']) &&
                !$this->isCsrfExempt($request)) {

                $csrfProtection = $this->container->get(CsrfProtection::class);

                if (!$request->validateCsrf($csrfProtection)) {
                    $response = new Response('CSRF token validation failed.', 419);
                    $response->send();
                    return;
                }
            }

            // Match the route
            $route = $this->router->match($request->getMethod(), $request->getUri());

            // Resolve the action
            $action = $route['action'];
            $parameters = $route['parameters'];

            // Execute the action with the container
            $response = $this->container->call($action, array_merge(['request' => $request], $parameters));

            // If the action didn't return a response, create a default one
            if (!$response instanceof Response) {
                $response = new Response((string)$response);
            }

            // Send the response
            $response->send();
        } catch (\Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Check if request is exempt from CSRF validation
     */
    private function isCsrfExempt(Request $request): bool
    {
        $exemptRoutes = $this->config->get('csrf.exempt', []);
        $uri = $request->getUri();

        foreach ($exemptRoutes as $route) {
            if ($route === $uri || (str_ends_with($route, '*') &&
                    str_starts_with($uri, substr($route, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle an exception
     */
    private function handleException(\Exception $e): void
    {
        $this->logger->error('Application Exception', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        $statusCode = 500;

        // Set appropriate status code based on exception type
        if ($e instanceof \RuntimeException) {
            $statusCode = 404;
        }

        // Custom template for error response
        if ($this->config->get('app.debug', false)) {
            $response = new Response(
                $this->renderExceptionTemplate($e),
                $statusCode
            );
        } else {
            $response = new Response(
                'An error occurred. Please try again later.',
                $statusCode
            );
        }

        $response->send();
    }

    /**
     * Render exception template for debug mode
     */
    private function renderExceptionTemplate(\Exception $e): string
    {
        ob_start();
        include dirname(__DIR__) . '/views/error/exception.php';
        return ob_get_clean();
    }

    /**
     * Get the application container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Get the application router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Get the application logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * Get the application configuration
     */
    public function getConfig(): AppConfig
    {
        return $this->config;
    }

    /**
     * Get database manager
     */
    public function getDbManager(): ?DatabaseManager
    {
        return $this->dbManager;
    }
}