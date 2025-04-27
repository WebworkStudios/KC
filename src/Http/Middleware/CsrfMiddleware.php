<?php

namespace Src\Http\Middleware;

use Src\Http\Middleware;
use Src\Http\Request;
use Src\Http\Response;
use Src\Log\LoggerInterface;
use Src\Log\NullLogger;
use Src\Security\CsrfTokenManager;

/**
 * Middleware für CSRF-Schutz
 *
 * Validiert CSRF-Tokens in Formularen und AJAX-Anfragen
 */
class CsrfMiddleware implements Middleware
{
    /** @var CsrfTokenManager CSRF-Token-Manager */
    private CsrfTokenManager $tokenManager;

    /** @var LoggerInterface Logger für CSRF-Validierung */
    private LoggerInterface $logger;

    /** @var array Konfigurationsoptionen */
    private array $config;

    /**
     * Erstellt eine neue CsrfMiddleware
     *
     * @param CsrfTokenManager $tokenManager CSRF-Token-Manager
     * @param LoggerInterface|null $logger Optional: Logger für CSRF-Validierung
     * @param array $config Konfigurationsoptionen
     */
    public function __construct(
        CsrfTokenManager $tokenManager,
        ?LoggerInterface $logger = null,
        array            $config = []
    )
    {
        $this->tokenManager = $tokenManager;
        $this->logger = $logger ?? new NullLogger();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Gibt die Standardkonfiguration zurück
     *
     * @return array Standardkonfiguration
     */
    private function getDefaultConfig(): array
    {
        return [
            'token_name' => '_csrf',                  // Name des CSRF-Tokens im Formular
            'token_header' => 'X-CSRF-Token',         // Header für AJAX-Anfragen
            'parameter_name' => '_csrf',              // Name des Parameters in der Anfrage
            'lifetime' => 3600,                       // Gültigkeitsdauer des Tokens in Sekunden
            'session_key' => '_csrf',                 // Schlüssel in der Session
            'https_only' => false,                    // Nur HTTPS-Anfragen erlauben
            'enabled' => true,                        // CSRF-Schutz aktivieren
            'exclude_methods' => ['GET', 'HEAD', 'OPTIONS'], // HTTP-Methoden, die nicht geschützt werden
            'exclude_routes' => [],                   // Routen, die nicht geschützt werden
            'exclude_paths' => [],                    // Pfade, die nicht geschützt werden (Wildcards erlaubt)
            'error_message' => 'CSRF-Token ungültig oder abgelaufen. Bitte versuchen Sie es erneut.', // Fehlermeldung
            'error_code' => 403,                     // HTTP-Statuscode bei ungültigem Token
            'auto_cleaner' => true,                  // Abgelaufene Tokens automatisch bereinigen
            'cleaner_chance' => 0.1,                 // Wahrscheinlichkeit für Bereinigung (0-1)
            'auto_regenerate' => true,               // Token automatisch regenerieren
            'rotate_frequency' => 300,               // Häufigkeit der Token-Rotation in Sekunden
            'strict_mode' => true,                   // Validierung überspringen, wenn kein Token in der Session
            'dynamic_tokens' => true,                // Für jede Formular-ID ein eigenes Token verwenden
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function process(Request $request, callable $next): ?Response
    {
        // CSRF-Schutz deaktivieren, wenn nicht aktiviert
        if (!$this->config['enabled']) {
            return $next($request);
        }

        // Bei HTTPS-Only-Konfiguration HTTPS erzwingen
        if ($this->config['https_only'] && !$request->isSecure()) {
            $this->logger->warning('CSRF-Middleware: Nicht-HTTPS-Anfrage abgelehnt');
            return $this->createErrorResponse($request);
        }

        // HTTP-Methode prüfen
        $method = $request->getMethod();
        if (in_array($method, $this->config['exclude_methods'], true)) {
            // GET, HEAD, OPTIONS usw. sind ausgenommen
            return $next($request);
        }

        // Ausgeschlossene Routen/Pfade prüfen
        $path = $request->getPath();

        // Exakte Routen prüfen
        if (in_array($path, $this->config['exclude_routes'], true)) {
            return $next($request);
        }

        // Wildcard-Pfade prüfen
        foreach ($this->config['exclude_paths'] as $excludePath) {
            if ($this->pathMatchesPattern($path, $excludePath)) {
                return $next($request);
            }
        }

        // CSRF-Token bereinigen, wenn aktiviert
        if ($this->config['auto_cleaner'] && $this->config['cleaner_chance'] > 0) {
            if (mt_rand(1, 1000) / 1000 <= $this->config['cleaner_chance']) {
                $this->tokenManager->cleanExpiredTokens();
            }
        }

        // Token-ID bestimmen (dynamisch oder statisch)
        $tokenId = $this->config['dynamic_tokens']
            ? $this->getTokenId($request)
            : $this->config['token_name'];

        // CSRF-Token aus Anfrage extrahieren
        $token = $this->extractToken($request);

        // Kein Token gefunden
        if ($token === null) {
            $this->logger->warning('CSRF-Middleware: Kein Token in der Anfrage gefunden', [
                'method' => $method,
                'path' => $path
            ]);

            return $this->createErrorResponse($request);
        }

        // Token validieren
        if (!$this->tokenManager->validateToken($tokenId, $token, $this->config['auto_regenerate'])) {
            $this->logger->warning('CSRF-Middleware: Ungültiges Token', [
                'method' => $method,
                'path' => $path,
                'token_id' => $tokenId
            ]);

            return $this->createErrorResponse($request);
        }

        // Anfrage weiterleiten
        return $next($request);
    }

    /**
     * Erstellt eine Fehler-Response bei ungültigem CSRF-Token
     *
     * @param Request $request HTTP-Anfrage
     * @return Response HTTP-Antwort
     */
    private function createErrorResponse(Request $request): Response
    {
        $message = $this->config['error_message'];
        $code = $this->config['error_code'];

        // JSON-Antwort für AJAX-Anfragen
        if ($request->isAjax()) {
            return Response::json([
                'error' => true,
                'message' => $message
            ], $code);
        }

        // HTML-Antwort für normale Anfragen
        return new Response(
            $this->createErrorPage($message),
            $code,
            'text/html'
        );
    }

    /**
     * Erstellt eine einfache Fehlerseite
     *
     * @param string $message Fehlermeldung
     * @return string HTML-Inhalt
     */
    private function createErrorPage(string $message): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sicherheitsfehler</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            background-color: #f8f9fa;
            color: #343a40;
            line-height: 1.6;
            margin: 0;
            padding: 2rem;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .error-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 2rem;
            max-width: 600px;
            width: 100%;
            text-align: center;
        }
        h1 {
            color: #dc3545;
            margin-top: 0;
        }
        .back-button {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            margin-top: 1rem;
            transition: background-color 0.3s;
        }
        .back-button:hover {
            background-color: #0069d9;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Sicherheitsfehler</h1>
        <p>{$message}</p>
        <p>Aus Sicherheitsgründen wurde diese Anfrage blockiert.</p>
        <a href="javascript:history.back()" class="back-button">Zurück zur vorherigen Seite</a>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Prüft, ob ein Pfad einem Muster entspricht (mit Wildcards)
     *
     * @param string $path Zu prüfender Pfad
     * @param string $pattern Muster (mit * als Wildcard)
     * @return bool True, wenn der Pfad dem Muster entspricht
     */
    private function pathMatchesPattern(string $path, string $pattern): bool
    {
        // Wenn das Muster * enthält, fnmatch verwenden
        if (strpos($pattern, '*') !== false) {
            return fnmatch($pattern, $path);
        }

        // Ansonsten exakte Übereinstimmung prüfen
        return $path === $pattern;
    }

    /**
     * Bestimmt die Token-ID basierend auf der Anfrage
     *
     * @param Request $request HTTP-Anfrage
     * @return string Token-ID
     */
    private function getTokenId(Request $request): string
    {
        // Standard-ID
        $tokenId = $this->config['token_name'];

        // Formular-ID aus der Anfrage extrahieren
        $formId = $request->post('_form_id') ?? $request->getRouteParameter('_form_id');

        if ($formId !== null) {
            $tokenId .= '_' . $formId;
        } else {
            // Ansonsten Pfad verwenden
            $path = $request->getPath();
            // Slash durch Unterstrich ersetzen für den Token-Namen
            $pathId = str_replace(['/', '.'], '_', trim($path, '/'));

            if (!empty($pathId)) {
                $tokenId .= '_' . $pathId;
            }
        }

        return $tokenId;
    }

    /**
     * Extrahiert das CSRF-Token aus der Anfrage
     *
     * @param Request $request HTTP-Anfrage
     * @return string|null Token oder null, wenn nicht gefunden
     */
    private function extractToken(Request $request): ?string
    {
        $paramName = $this->config['parameter_name'];
        $headerName = $this->config['token_header'];

        // Token aus POST-Parameter
        if ($request->hasPost($paramName)) {
            return $request->post($paramName);
        }

        // Token aus Body-Parameter (für PUT, DELETE, usw.)
        $body = $request->getBody();
        if (is_array($body) && isset($body[$paramName])) {
            return $body[$paramName];
        }

        // Token aus Header
        $header = $request->getHeader($headerName);
        if ($header !== null) {
            return $header;
        }

        // Token aus X-Header (fallback)
        $header = $request->getHeader('HTTP_' . str_replace('-', '_', strtoupper($headerName)));
        if ($header !== null) {
            return $header;
        }

        return null;
    }

    /**
     * Generiert ein CSRF-Token für ein Formular
     *
     * @param string|null $formId Optional: Formular-ID für individuelle Tokens
     * @param int|null $lifetime Optional: Gültigkeitsdauer in Sekunden
     * @return string HTML-Input-Feld mit CSRF-Token
     */
    public function generateTokenField(?string $formId = null, ?int $lifetime = null): string
    {
        // Token-ID bestimmen
        $tokenId = $this->config['token_name'];

        if ($formId !== null) {
            $tokenId .= '_' . $formId;
        }

        // Token generieren
        $token = $this->tokenManager->getToken($tokenId, $lifetime ?? $this->config['lifetime']);

        // HTML-Input-Feld erstellen
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($this->config['parameter_name']),
            htmlspecialchars($token->getValue())
        );
    }

    /**
     * Generiert ein CSRF-Token-Meta-Tag für AJAX-Anfragen
     *
     * @param string|null $formId Optional: Formular-ID für individuelle Tokens
     * @param int|null $lifetime Optional: Gültigkeitsdauer in Sekunden
     * @return string HTML-Meta-Tag mit CSRF-Token
     */
    public function generateTokenMeta(?string $formId = null, ?int $lifetime = null): string
    {
        // Token-ID bestimmen
        $tokenId = $this->config['token_name'];

        if ($formId !== null) {
            $tokenId .= '_' . $formId;
        }

        // Token generieren
        $token = $this->tokenManager->getToken($tokenId, $lifetime ?? $this->config['lifetime']);

        // HTML-Meta-Tag erstellen
        return sprintf(
            '<meta name="csrf-token" content="%s">',
            htmlspecialchars($token->getValue())
        );
    }

    /**
     * Generiert JavaScript für automatisches Hinzufügen von CSRF-Token zu AJAX-Anfragen
     *
     * @return string JavaScript-Code
     */
    public function generateTokenJavaScript(): string
    {
        $headerName = $this->config['token_header'];

        return <<<JS
<script>
(function() {
    // CSRF-Token aus Meta-Tag holen
    let token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    
    if (token) {
        // Token zu allen AJAX-Anfragen hinzufügen
        let originalSend = XMLHttpRequest.prototype.send;
        let originalOpen = XMLHttpRequest.prototype.open;
        
        // open überschreiben, um die Methode zu speichern
        XMLHttpRequest.prototype.open = function(method, url) {
            this._csrfMethod = method.toUpperCase();
            originalOpen.apply(this, arguments);
        };
        
        // send überschreiben, um den Header hinzuzufügen
        XMLHttpRequest.prototype.send = function(data) {
            // Header nur für Methoden hinzufügen, die nicht ausgeschlossen sind
            let excludedMethods = ['GET', 'HEAD', 'OPTIONS'];
            if (!excludedMethods.includes(this._csrfMethod)) {
                this.setRequestHeader('{$headerName}', token);
            }
            originalSend.apply(this, arguments);
        };
        
        // Fetch API verwenden
        if (window.fetch) {
            let originalFetch = window.fetch;
            window.fetch = function(resource, options = {}) {
                options = options || {};
                
                // Methode bestimmen
                let method = options.method?.toUpperCase() || 'GET';
                
                // Header nur für Methoden hinzufügen, die nicht ausgeschlossen sind
                if (!excludedMethods.includes(method)) {
                    options.headers = options.headers || {};
                    
                    // Header-Objekt oder Map verwenden
                    if (options.headers instanceof Headers) {
                        options.headers.append('{$headerName}', token);
                    } else if (options.headers instanceof Map) {
                        options.headers.set('{$headerName}', token);
                    } else {
                        options.headers['{$headerName}'] = token;
                    }
                }
                
                return originalFetch.call(window, resource, options);
            };
        }
    }
})();
</script>
JS;
    }
}