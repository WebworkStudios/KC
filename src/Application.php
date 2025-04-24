<?php
namespace Src;

use Src\Config\AppConfig;
use Src\Config\Container;
use Src\Http\Request;
use Src\Http\Response;
use Src\Routing\Router;
use Src\Utils\Logger;

class Application
{
    private Container $container;
    private AppConfig $config;
    private Logger $logger;
    private Router $router;

    /**
     * Create a new Application instance
     */
    public function __construct()
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

        // Register router
        $this->router = new Router($this->config);
        $this->container->instance(Router::class, $this->router);

        // Register request
        $this->container->singleton(Request::class, function () {
            return Request::createFromGlobals();
        });

        // Bootstrap the application
        $this->bootstrap();
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
    }

    /**
     * Register application routes
     */
    private function registerRoutes(): void
    {
        $routesFile = dirname(__DIR__) . '/config/routes.php';

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
    }

    /**
     * Handle the current request
     */
    public function handle(): void
    {
        try {
            // Get the current request
            $request = $this->container->get(Request::class);

            // Match the route
            $route = $this->router->match($request->getMethod(), $request->getUri());

            // Resolve the action
            $action = $route['action'];
            $parameters = $route['parameters'];

            // Execute the action with the container
            $instance = $this->container->make($action);

            // Add route parameters to the request before passing it to the action
            $response = $instance($request, ...$parameters);

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

        $response = new Response(
            'An error occurred: ' . $e->getMessage(),
            $statusCode
        );

        $response->send();
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
}