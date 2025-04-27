<?php

namespace Src\Security;

/**
 * Hilfsfunktionen für CSRF-Schutz
 *
 * Bietet einfache Helfer-Methoden zur Integration von CSRF-Schutz in Templates
 */
class CsrfHelper
{
    /** @var CsrfTokenManager CSRF-Token-Manager */
    private CsrfTokenManager $tokenManager;

    /** @var array Konfigurationsoptionen */
    private array $config;

    /**
     * Erstellt einen neuen CSRF-Helper
     *
     * @param CsrfTokenManager $tokenManager CSRF-Token-Manager
     * @param array $config Konfigurationsoptionen
     */
    public function __construct(CsrfTokenManager $tokenManager, array $config = [])
    {
        $this->tokenManager = $tokenManager;
        $this->config = array_merge([
            'parameter_name' => '_csrf',
            'token_name' => '_csrf',
            'lifetime' => 3600,
        ], $config);
    }

    /**
     * Generiert ein HTML-Input-Feld mit CSRF-Token
     *
     * @param string|null $formId Optional: Formular-ID für individuelle Tokens
     * @param int|null $lifetime Optional: Gültigkeitsdauer in Sekunden
     * @return string HTML-Input-Feld
     */
    public function tokenField(?string $formId = null, ?int $lifetime = null): string
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
     * Generiert ein Meta-Tag mit CSRF-Token für JavaScript
     *
     * @param string|null $formId Optional: Formular-ID für individuelle Tokens
     * @param int|null $lifetime Optional: Gültigkeitsdauer in Sekunden
     * @return string HTML-Meta-Tag
     */
    public function tokenMeta(?string $formId = null, ?int $lifetime = null): string
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
     * @param string $headerName HTTP-Header-Name für das Token
     * @return string JavaScript-Code
     */
    public function tokenScript(string $headerName = 'X-CSRF-Token'): string
    {
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
        
        console.log('CSRF protection initialized');
    }
})();
</script>
JS;
    }

    /**
     * Generiert ein CSRF-Token-String für API-Anfragen
     *
     * @param string|null $formId Optional: Formular-ID für individuelle Tokens
     * @param int|null $lifetime Optional: Gültigkeitsdauer in Sekunden
     * @return string Token-Wert
     */
    public function tokenValue(?string $formId = null, ?int $lifetime = null): string
    {
        // Token-ID bestimmen
        $tokenId = $this->config['token_name'];

        if ($formId !== null) {
            $tokenId .= '_' . $formId;
        }

        // Token generieren
        $token = $this->tokenManager->getToken($tokenId, $lifetime ?? $this->config['lifetime']);

        return $token->getValue();
    }
}