<?php

namespace Src\Http;

use Src\Security\CsrfProtection;
use Src\Security\InputFilter;

class Request
{
    private array $queryParams;
    private array $postData;
    private array $cookies;
    private array $files;
    private array $server;
    private array $headers;
    private string $method;
    private string $uri;
    private string $clientIp;
    private ?string $body = null;
    private ?array $jsonData = null;

    /**
     * Create a new request instance from globals
     *
     * @return self The new request instance
     */
    public static function createFromGlobals(): self
    {
        $request = new self();

        $request->queryParams = $_GET;
        $request->postData = $_POST;
        $request->cookies = $_COOKIE;
        $request->files = $_FILES;
        $request->server = $_SERVER;

        // Parse headers from server variables
        $request->headers = $request->parseHeaders();

        // Determine the correct HTTP method
        $request->method = $request->detectMethod();

        // Get the URI from server variables
        $request->uri = $request->detectUri();

        // Get client IP
        $request->clientIp = $request->detectClientIp();

        return $request;
    }

    /**
     * Parse headers from server variables
     *
     * @return array Parsed headers
     */
    private function parseHeaders(): array
    {
        $headers = [];

        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                // Konvertiere HTTP_ACCEPT_LANGUAGE zu Accept-Language
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$this->normalizeHeaderName($name)] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = str_replace('_', '-', $key);
                $headers[$this->normalizeHeaderName($name)] = $value;
            }
        }

        return $headers;
    }

    /**
     * Normalize a header name to standard format
     *
     * @param string $name The header name to normalize
     * @return string The normalized header name
     */
    private function normalizeHeaderName(string $name): string
    {
        // Konvertiere zu Titel-Schreibweise mit Bindestrichen
        // z.B. ACCEPT-LANGUAGE zu Accept-Language
        $name = strtolower($name);
        return str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
    }

    /**
     * Detect the actual HTTP method
     *
     * @return string The detected HTTP method
     */
    private function detectMethod(): string
    {
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';

        // Check for method override in POST data
        if ($method === 'POST') {
            if (isset($this->postData['_method'])) {
                return strtoupper($this->postData['_method']);
            }

            // Check for X-HTTP-Method-Override header
            if (isset($this->headers['X-Http-Method-Override'])) {
                return strtoupper($this->headers['X-Http-Method-Override']);
            }
        }

        return $method;
    }

    /**
     * Detect the request URI
     *
     * @return string The detected URI
     */
    private function detectUri(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';

        // Remove query string if present
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        return $uri;
    }

    /**
     * Detect client IP address with improved security
     *
     * @return string The detected client IP address
     */
    private function detectClientIp(): string
    {
        $ipKeys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        $trustedProxies = ['127.0.0.1', '::1']; // Hier vertrauenswürdige Proxies konfigurieren

        foreach ($ipKeys as $key) {
            if (!isset($this->server[$key])) {
                continue;
            }

            // Mehrere durch Komma getrennte IPs verarbeiten (X-Forwarded-For enthält mehrere)
            $ips = explode(',', $this->server[$key]);
            $ips = array_map('trim', $ips);

            foreach ($ips as $ip) {
                // Nur öffentliche IPs akzeptieren
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Get a query parameter
     *
     * @param string $key Parameter name
     * @param mixed $default Default value if parameter not found
     * @return mixed Parameter value
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Get all query parameters
     *
     * @return array All query parameters
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Get a POST value
     *
     * @param string $key Parameter name
     * @param mixed $default Default value if parameter not found
     * @return mixed Parameter value
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->postData[$key] ?? $default;
    }

    /**
     * Get all POST data
     *
     * @return array All POST data
     */
    public function getPostData(): array
    {
        return $this->postData;
    }

    /**
     * Get request input (combines GET, POST and JSON body)
     *
     * @param string $key Parameter name
     * @param mixed $default Default value if parameter not found
     * @return mixed Parameter value
     */
    public function input(string $key, mixed $default = null): mixed
    {
        // Check sources in priority order: POST, GET, JSON
        $sources = [
            $this->postData,
            $this->queryParams,
            $this->isJson() ? $this->getJsonBody() : []
        ];

        foreach ($sources as $source) {
            if (isset($source[$key])) {
                return $source[$key];
            }
        }

        return $default;
    }

    /**
     * Get all input data (combines GET, POST and JSON body)
     *
     * @return array All input data
     */
    public function all(): array
    {
        $input = array_merge($this->queryParams, $this->postData);

        if ($this->isJson()) {
            $input = array_merge($input, $this->getJsonBody());
        }

        return $input;
    }

    /**
     * Get filtered input value
     *
     * @param string $key Parameter name
     * @param mixed $default Default value if parameter not found
     * @param int $filter Filter type from PHP's filter constants
     * @return mixed Filtered parameter value
     */
    public function filter(string $key, mixed $default = null, int $filter = FILTER_SANITIZE_FULL_SPECIAL_CHARS): mixed
    {
        $value = $this->input($key, $default);

        return InputFilter::sanitize($value, $filter);
    }

    /**
     * Check if input has a specific key
     *
     * @param string $key The key to check for
     * @return bool True if the key exists, false otherwise
     */
    public function has(string $key): bool
    {
        return isset($this->postData[$key]) ||
            isset($this->queryParams[$key]) ||
            ($this->isJson() && isset($this->getJsonBody()[$key]));
    }

    /**
     * Get request body content
     *
     * @return string Raw request body
     */
    public function getBody(): string
    {
        if ($this->body === null) {
            $this->body = file_get_contents('php://input') ?: '';
        }

        return $this->body;
    }

    /**
     * Check if request has JSON content type
     *
     * @return bool True if content type is JSON, false otherwise
     */
    public function isJson(): bool
    {
        $contentType = $this->getHeader('Content-Type');
        return $contentType && str_contains($contentType, 'application/json');
    }

    /**
     * Get JSON decoded body
     *
     * @return array Decoded JSON data
     */
    public function getJsonBody(): array
    {
        if ($this->jsonData === null) {
            if (!$this->isJson()) {
                $this->jsonData = [];
            } else {
                $body = $this->getBody();
                try {
                    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    $this->jsonData = is_array($data) ? $data : [];
                } catch (\JsonException $e) {
                    // Return empty array on JSON decoding error
                    $this->jsonData = [];
                }
            }
        }

        return $this->jsonData;
    }

    /**
     * Get a cookie value
     *
     * @param string $key Cookie name
     * @param mixed $default Default value if cookie not found
     * @return mixed Cookie value or default
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get all cookies
     *
     * @return array All cookies
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Get an uploaded file
     *
     * @param string $key File input name
     * @return array|null File data or null if not found
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Get all uploaded files
     *
     * @return array All uploaded files
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Check if file was uploaded
     *
     * @param string $key File input name
     * @return bool True if file was uploaded, false otherwise
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) &&
            $this->files[$key]['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Get a server variable
     *
     * @param string $key Server variable name
     * @param mixed $default Default value if server variable not found
     * @return mixed Server variable value or default
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Get request HTTP method
     *
     * @return string The HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Check if request uses a specific HTTP method
     *
     * @param string $method The method to check
     * @return bool True if the request method matches, false otherwise
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }

    /**
     * Get request URI
     *
     * @return string The request URI
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get a request header
     *
     * @param string $name Header name (case-insensitive)
     * @param mixed $default Default value if header not found
     * @return mixed Header value or default
     */
    public function getHeader(string $name, mixed $default = null): mixed
    {
        // Normalisiere den Header-Namen für den internen Vergleich
        $normalizedName = $this->normalizeHeaderName($name);

        // Suche in den normalisierten Header-Namen
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($this->normalizeHeaderName($key), $normalizedName) === 0) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get all request headers
     *
     * @return array All headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Check if request has a specific header
     *
     * @param string $name Header name (case-insensitive)
     * @return bool True if the header exists, false otherwise
     */
    public function hasHeader(string $name): bool
    {
        $normalizedName = $this->normalizeHeaderName($name);

        foreach ($this->headers as $key => $value) {
            if (strcasecmp($this->normalizeHeaderName($key), $normalizedName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get client IP address
     *
     * @return string The client IP address
     */
    public function getClientIp(): string
    {
        return $this->clientIp;
    }

    /**
     * Check if request was made via AJAX
     *
     * @return bool True if request was made via AJAX, false otherwise
     */
    public function isAjax(): bool
    {
        return $this->getHeader('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Check if request is HTTPS
     *
     * @return bool True if request is secure, false otherwise
     */
    public function isSecure(): bool
    {
        if ((isset($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ||
            (isset($this->server['SERVER_PORT']) && $this->server['SERVER_PORT'] === 443)) {
            return true;
        }

        // Prüfen auf Proxy-Header, falls hinter einem Load Balancer
        return $this->getHeader('X-Forwarded-Proto') === 'https';
    }

    /**
     * Validate CSRF token
     *
     * @param CsrfProtection $csrf The CSRF protection instance
     * @return bool True if CSRF token is valid, false otherwise
     */
    public function validateCsrf(CsrfProtection $csrf): bool
    {
        $token = $this->input('_csrf') ?? $this->getHeader('X-Csrf-Token');

        if (!$token) {
            return false;
        }

        return $csrf->validateToken($token);
    }
}