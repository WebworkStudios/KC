<?php


/**
 * Helper-Funktionen für die Anwendung
 */

if (!function_exists('app')) {
    /**
     * Holt die Anwendungsinstanz oder eine registrierte Komponente
     *
     * @param string|null $abstract Klassenname oder Abstraktion der Komponente
     * @return mixed
     */
    function app(?string $abstract = null)
    {
        $app = Src\Application::getInstance();

        if ($abstract === null) {
            return $app;
        }

        return $app->getContainer()->get($abstract);
    }
}

if (!function_exists('config')) {
    /**
     * Holt einen Konfigurationswert
     *
     * @param string $key Konfigurationsschlüssel (mit Punktnotation)
     * @param mixed $default Standardwert falls Schlüssel nicht existiert
     * @return mixed
     */
    function config(string $key, $default = null)
    {
        return app(Src\Config\AppConfig::class)->get($key, $default);
    }
}

if (!function_exists('view')) {
    /**
     * Rendert eine View mit Daten
     *
     * @param string $template View-Template
     * @param array $data Daten für die View
     * @return string Gerenderte View
     */
    function view(string $template, array $data = []): string
    {
        $viewPath = BASE_PATH . '/views/' . $template . '.php';

        if (!file_exists($viewPath)) {
            throw new RuntimeException("View not found: $template");
        }

        ob_start();
        extract($data);
        include $viewPath;
        return ob_get_clean();
    }
}

if (!function_exists('redirect')) {
    /**
     * Erstellt eine Redirect-Response
     *
     * @param string $url Ziel-URL
     * @param int $status HTTP-Statuscode
     * @return Src\Http\Response
     */
    function redirect(string $url, int $status = 302): Src\Http\Response
    {
        return (new Src\Http\Response())->redirect($url, $status);
    }
}

if (!function_exists('route')) {
    /**
     * Generiert eine URL für eine Route
     *
     * @param string $name Name der Route
     * @param array $parameters Parameter für die Route
     * @return string URL
     */
    function route(string $name, array $parameters = []): string
    {
        return app(Src\Routing\Router::class)->url($name, $parameters);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Generiert ein CSRF-Token
     *
     * @return string CSRF-Token
     */
    function csrf_token(): string
    {
        return app(Src\Security\CsrfProtection::class)->getToken();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generiert ein CSRF-Token-Feld
     *
     * @return string HTML für das CSRF-Token-Feld
     */
    function csrf_field(): string
    {
        return app(Src\Security\CsrfProtection::class)->tokenField();
    }
}

// In src/Utils/Helpers.php

if (!function_exists('old')) {
    /**
     * Holt einen alten Eingabewert aus der Session
     *
     * @param string $key Schlüssel
     * @param mixed $default Standardwert
     * @return mixed
     */
    function old(string $key, $default = null)
    {
        return app(Src\Security\Session::class)->getOldInput($key, $default);
    }
}

if (!function_exists('e')) {
    /**
     * Escaped HTML-Entitäten
     *
     * @param string|null $value Zu escapender Wert
     * @return string Escapeder Wert
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset')) {
    /**
     * Generiert eine URL für eine Asset-Datei
     *
     * @param string $path Pfad zur Asset-Datei
     * @return string URL
     */
    function asset(string $path): string
    {
        $basePath = config('app.url', '') . '/assets';
        return $basePath . '/' . ltrim($path, '/');
    }
}

if (!function_exists('logger')) {
    /**
     * Protokolliert eine Nachricht
     *
     * @param string $message Nachricht
     * @param array $context Kontext
     * @param string $level Log-Level
     * @return void
     */
    function logger(string $message, array $context = [], string $level = 'info'): void
    {
        app(Src\Utils\Logger::class)->log($level, $message, $context);
    }
}