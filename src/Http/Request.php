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

    /**
     * Create a new request instance from globals
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
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = str_replace('_', '-', $key);
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Detect the actual HTTP method
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
            if (isset($this->headers['X-HTTP-METHOD-OVERRIDE'])) {
                return strtoupper($this->headers['X-HTTP-METHOD-OVERRIDE']);
            }
        }

        return $method;
    }

    /**
     * Detect the request URI
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
     * Detect client IP address
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

        foreach ($ipKeys as $key) {
            if (isset($this->server[$key]) && filter_var($this->server[$key], FILTER_VALIDATE_IP)) {
                return $this->server[$key];
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
     */
    public function filter(string $key, $default = null, int $filter = FILTER_SANITIZE_SPECIAL_CHARS)
    {
        $value = $this->input($key, $default);

        return InputFilter::sanitize($value, $filter);
    }

    /**
     * Check if input has a specific key
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
     */
    public function isJson(): bool
    {
        $contentType = $this->getHeader('CONTENT-TYPE');
        return $contentType && str_contains($contentType, 'application/json');
    }

    /**
     * Get JSON decoded body
     *
     * @return array Decoded JSON data
     */
    public function getJsonBody(): array
    {
        static $jsonData = null;

        if ($jsonData === null) {
            if (!$this->isJson()) {
                $jsonData = [];
            } else {
                $body = $this->getBody();
                $data = json_decode($body, true);
                $jsonData = is_array($data) ? $data : [];
            }
        }

        return $jsonData;
    }

    /**
     * Get a cookie value
     */
    public function cookie(string $key, $default = null)
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get all cookies
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Get an uploaded file
     */
    public function file(string $key)
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Get all uploaded files
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Check if file was uploaded
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) &&
            $this->files[$key]['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Get a server variable
     */
    public function server(string $key, $default = null)
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Get request HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Check if request uses a specific HTTP method
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }

    /**
     * Get request URI
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get a request header
     */
    public function getHeader(string $name, $default = null)
    {
        $name = strtoupper(str_replace('-', '_', $name));
        return $this->headers[$name] ?? $default;
    }

    /**
     * Get all request headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Check if request has a specific header
     */
    public function hasHeader(string $name): bool
    {
        $name = strtoupper(str_replace('-', '_', $name));
        return isset($this->headers[$name]);
    }

    /**
     * Get client IP address
     */
    public function getClientIp(): string
    {
        return $this->clientIp;
    }

    /**
     * Check if request was made via AJAX
     */
    public function isAjax(): bool
    {
        return $this->getHeader('X-REQUESTED-WITH') === 'XMLHttpRequest';
    }

    /**
     * Check if request is HTTPS
     */
    public function isSecure(): bool
    {
        return (isset($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ||
            $this->server['SERVER_PORT'] === 443;
    }

    /**
     * Validate CSRF token
     */
    public function validateCsrf(CsrfProtection $csrf): bool
    {
        $token = $this->input('_csrf') ?? $this->getHeader('X-CSRF-TOKEN');

        if (!$token) {
            return false;
        }

        return $csrf->validateToken($token);
    }
}