<?php

namespace Src\Http;

/**
 * HTTP Response Klasse
 *
 * Stellt eine HTTP-Antwort dar und bietet Methoden zum Setzen von Headern,
 * Cookies, Status-Codes und Inhalten.
 */
class Response
{
    /**
     * HTTP Status-Code-Konstanten
     */
    public const HTTP_OK = 200;
    public const HTTP_CREATED = 201;
    public const HTTP_ACCEPTED = 202;
    public const HTTP_NO_CONTENT = 204;
    public const HTTP_MOVED_PERMANENTLY = 301;
    public const HTTP_FOUND = 302;
    public const HTTP_SEE_OTHER = 303;
    public const HTTP_NOT_MODIFIED = 304;
    public const HTTP_TEMPORARY_REDIRECT = 307;
    public const HTTP_PERMANENT_REDIRECT = 308;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
    public const HTTP_UNPROCESSABLE_ENTITY = 422;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;
    public const HTTP_SERVICE_UNAVAILABLE = 503;

    private int $statusCode = self::HTTP_OK;
    private array $headers = [];
    private ?string $content = null;
    private array $cookies = [];
    private string $contentType = 'text/html';
    private string $charset = 'UTF-8';

    /**
     * Create a new response instance
     *
     * @param string|null $content The response content
     * @param int $statusCode The HTTP status code
     * @param array $headers Additional response headers
     */
    public function __construct(?string $content = null, int $statusCode = self::HTTP_OK, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;

        // Set default content type header
        $this->setHeader('Content-Type', $this->getFullContentType());
    }

    /**
     * Get the full content type including charset if applicable
     *
     * @return string The full content-type header value
     */
    private function getFullContentType(): string
    {
        // Only add charset for text-based content types
        if (str_starts_with($this->contentType, 'text/') ||
            in_array($this->contentType, ['application/json', 'application/xml', 'application/javascript'], true)) {
            return $this->contentType . '; charset=' . $this->charset;
        }

        return $this->contentType;
    }

    /**
     * Set response status code
     *
     * @param int $statusCode HTTP status code
     * @return self Fluent interface
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Get response status code
     *
     * @return int Current HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set response content
     *
     * @param string|null $content Response body content
     * @return self Fluent interface
     */
    public function setContent(?string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Get response content
     *
     * @return string|null Current response body content
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * Set response charset
     *
     * @param string $charset Character set (e.g., 'UTF-8', 'ISO-8859-1')
     * @return self Fluent interface
     */
    public function setCharset(string $charset): self
    {
        $this->charset = $charset;

        // Update Content-Type header if it already exists
        if (isset($this->headers['Content-Type']) && $this->isCharsetApplicable($this->contentType)) {
            $this->setHeader('Content-Type', $this->getFullContentType());
        }

        return $this;
    }

    /**
     * Get current charset
     *
     * @return string Current character set
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * Check if charset should be applied to the given content type
     *
     * @param string $contentType MIME content type
     * @return bool True if charset is applicable
     */
    private function isCharsetApplicable(string $contentType): bool
    {
        return str_starts_with($contentType, 'text/') ||
            in_array($contentType, ['application/json', 'application/xml', 'application/javascript'], true);
    }

    /**
     * Set a response header
     *
     * @param string $name Header name
     * @param string|int|bool $value Header value
     * @return self Fluent interface
     */
    public function setHeader(string $name, $value): self
    {
        $this->headers[$name] = (string)$value;
        return $this;
    }

    /**
     * Set multiple headers at once
     *
     * @param array $headers Associative array of headers
     * @return self Fluent interface
     */
    public function setHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }

        return $this;
    }

    /**
     * Get all headers
     *
     * @return array All response headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Check if a header exists
     *
     * @param string $name Header name
     * @return bool True if header exists
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    /**
     * Remove a header
     *
     * @param string $name Header name
     * @return self Fluent interface
     */
    public function removeHeader(string $name): self
    {
        unset($this->headers[$name]);
        return $this;
    }

    /**
     * Set content type with proper charset handling
     *
     * @param string $contentType The MIME content type
     * @return self Fluent interface
     */
    public function setContentType(string $contentType): self
    {
        $this->contentType = $contentType;
        $this->setHeader('Content-Type', $this->getFullContentType());
        return $this;
    }

    /**
     * Get current content type (without charset)
     *
     * @return string Current MIME content type
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Set response as JSON
     *
     * @param mixed $data Data to be encoded as JSON
     * @param int $statusCode HTTP status code
     * @param int $options JSON encoding options
     * @return self Fluent interface
     * @throws \JsonException When JSON encoding fails
     */
    public function json(mixed $data, int $statusCode = self::HTTP_OK, int $options = JSON_THROW_ON_ERROR): self
    {
        $this->setContentType('application/json');
        $this->setStatusCode($statusCode);
        $this->setContent(json_encode($data, $options));

        return $this;
    }

    /**
     * Set response as HTML
     *
     * @param string $html HTML content
     * @param int $statusCode HTTP status code
     * @return self Fluent interface
     */
    public function html(string $html, int $statusCode = self::HTTP_OK): self
    {
        $this->setContentType('text/html');
        $this->setStatusCode($statusCode);
        $this->setContent($html);

        return $this;
    }

    /**
     * Set response as plain text
     *
     * @param string $text Plain text content
     * @param int $statusCode HTTP status code
     * @return self Fluent interface
     */
    public function text(string $text, int $statusCode = self::HTTP_OK): self
    {
        $this->setContentType('text/plain');
        $this->setStatusCode($statusCode);
        $this->setContent($text);

        return $this;
    }

    /**
     * Create a redirect response
     *
     * @param string $url URL to redirect to
     * @param int $statusCode HTTP status code
     * @return self Fluent interface
     */
    public function redirect(string $url, int $statusCode = self::HTTP_FOUND): self
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Location', $url);
        $this->setContent(null);

        return $this;
    }

    /**
     * Create a file download response
     *
     * @param string $filePath Path to the file
     * @param string|null $fileName Suggested filename for download
     * @param string $contentType MIME content type
     * @return self Fluent interface
     * @throws \RuntimeException If file doesn't exist or can't be read
     */
    public function download(string $filePath, ?string $fileName = null, string $contentType = 'application/octet-stream'): self
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException("File does not exist or is not readable: $filePath");
        }

        $fileName = $fileName ?? basename($filePath);

        // Sanitize the filename for security
        $fileName = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '_', $fileName);

        $this->setContentType($contentType);
        $this->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        $this->setHeader('Content-Length', (string)filesize($filePath));
        $this->setContent(file_get_contents($filePath));

        return $this;
    }

    /**
     * Stream a file as response
     *
     * @param string $filePath Path to the file
     * @param string|null $fileName Suggested filename
     * @param string|null $contentType MIME content type (auto-detected if null)
     * @return self Fluent interface
     * @throws \RuntimeException If file doesn't exist or can't be read
     */
    public function file(string $filePath, ?string $fileName = null, ?string $contentType = null): self
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException("File does not exist or is not readable: $filePath");
        }

        // Auto-detect content type if not provided
        if ($contentType === null) {
            $contentType = mime_content_type($filePath) ?: 'application/octet-stream';
        }

        $fileName = $fileName ?? basename($filePath);

        $this->setContentType($contentType);
        $this->setHeader('Content-Disposition', 'inline; filename="' . $fileName . '"');
        $this->setHeader('Content-Length', (string)filesize($filePath));
        $this->setContent(file_get_contents($filePath));

        return $this;
    }

    /**
     * Set a cookie with improved defaults for security
     *
     * @param string $name Cookie name
     * @param string $value Cookie value
     * @param int $expires Expiration timestamp
     * @param string $path Cookie path
     * @param string $domain Cookie domain
     * @param bool $secure Only transmit over HTTPS
     * @param bool $httpOnly Inaccessible to JavaScript
     * @param string $sameSite SameSite policy (Lax, Strict, None)
     * @return self Fluent interface
     * @throws \InvalidArgumentException If cookie name contains invalid characters
     */
    public function setCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        // Validate cookie name according to RFC 6265
        if (preg_match('/[=,; \t\r\n\013\014]/', $name)) {
            throw new \InvalidArgumentException('Cookie name cannot contain these characters: =,; \\t\\r\\n\\013\\014');
        }

        // Validate SameSite value
        $validSameSiteValues = ['None', 'Lax', 'Strict'];
        $sameSite = in_array($sameSite, $validSameSiteValues, true) ? $sameSite : 'Lax';

        // If SameSite is None, secure must be true according to spec
        if ($sameSite === 'None' && !$secure) {
            $secure = true;
        }

        $this->cookies[$name] = [
            'value' => $value,
            'expires' => $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];

        return $this;
    }

    /**
     * Remove a cookie
     *
     * @param string $name Cookie name
     * @param string $path Cookie path
     * @param string $domain Cookie domain
     * @return self Fluent interface
     */
    public function removeCookie(string $name, string $path = '/', string $domain = ''): self
    {
        // Set cookie with expiration in the past to remove it
        return $this->setCookie($name, '', time() - 3600, $path, $domain);
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
     * Set cache control headers
     *
     * @param int $seconds Cache lifetime in seconds
     * @param bool $public Public or private cache
     * @return self Fluent interface
     */
    public function cache(int $seconds, bool $public = true): self
    {
        $directive = $public ? 'public' : 'private';

        $this->setHeader(
            'Cache-Control',
            "$directive, max-age=$seconds, s-maxage=$seconds"
        );

        $this->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');

        return $this;
    }

    /**
     * Set no-cache headers
     *
     * @return self Fluent interface
     */
    public function noCache(): self
    {
        $this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->setHeader('Pragma', 'no-cache');
        $this->setHeader('Expires', '0');

        return $this;
    }

    /**
     * Set Content-Security-Policy header
     *
     * @param array $directives CSP directives
     * @return self Fluent interface
     */
    public function setContentSecurityPolicy(array $directives): self
    {
        $policy = [];

        foreach ($directives as $directive => $value) {
            if (is_array($value)) {
                $policy[] = $directive . ' ' . implode(' ', $value);
            } else {
                $policy[] = $directive . ' ' . $value;
            }
        }

        $this->setHeader('Content-Security-Policy', implode('; ', $policy));

        return $this;
    }

    /**
     * Enable response compression
     *
     * @param int $level Compression level (0-9)
     * @return self Fluent interface
     */
    public function compress(int $level = 6): self
    {
        if ($this->content === null) {
            return $this;
        }

        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

        // Check for gzip support
        if (strpos($acceptEncoding, 'gzip') !== false && function_exists('gzencode')) {
            $this->setContent(gzencode($this->content, $level));
            $this->setHeader('Content-Encoding', 'gzip');
        }
        // Check for brotli support (PHP 7.4+ with ext-brotli)
        elseif (strpos($acceptEncoding, 'br') !== false && function_exists('brotli_compress')) {
            $this->setContent(brotli_compress($this->content, $level));
            $this->setHeader('Content-Encoding', 'br');
        }

        // Update Content-Length header
        if ($this->content !== null) {
            $this->setHeader('Content-Length', (string)strlen($this->content));
        }

        return $this;
    }

    /**
     * Create a response for a specific HTTP status with standard message
     *
     * @param int $statusCode HTTP status code
     * @param string|null $customMessage Custom message (optional)
     * @return self Fluent interface
     */
    public function status(int $statusCode, ?string $customMessage = null): self
    {
        $this->setStatusCode($statusCode);

        $statusMessages = [
            self::HTTP_OK => 'OK',
            self::HTTP_CREATED => 'Created',
            self::HTTP_ACCEPTED => 'Accepted',
            self::HTTP_NO_CONTENT => 'No Content',
            self::HTTP_MOVED_PERMANENTLY => 'Moved Permanently',
            self::HTTP_FOUND => 'Found',
            self::HTTP_SEE_OTHER => 'See Other',
            self::HTTP_NOT_MODIFIED => 'Not Modified',
            self::HTTP_BAD_REQUEST => 'Bad Request',
            self::HTTP_UNAUTHORIZED => 'Unauthorized',
            self::HTTP_FORBIDDEN => 'Forbidden',
            self::HTTP_NOT_FOUND => 'Not Found',
            self::HTTP_METHOD_NOT_ALLOWED => 'Method Not Allowed',
            self::HTTP_UNPROCESSABLE_ENTITY => 'Unprocessable Entity',
            self::HTTP_INTERNAL_SERVER_ERROR => 'Internal Server Error',
            self::HTTP_SERVICE_UNAVAILABLE => 'Service Unavailable',
        ];

        // Use custom message or predefined message or generic fallback
        $message = $customMessage ?? $statusMessages[$statusCode] ?? 'HTTP Status ' . $statusCode;

        // Content is only set for error status codes or if a custom message is provided
        if ($statusCode >= 400 || $customMessage !== null) {
            $this->html('<h1>' . $statusCode . ' ' . $message . '</h1>', $statusCode);
        } else {
            $this->setContent(null);
        }

        return $this;
    }

    /**
     * Send HTTP response with improved error handling
     *
     * @throws \RuntimeException If headers have already been sent
     * @return void
     */
    public function send(): void
    {
        // Check if headers have already been sent
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Headers already sent in $file on line $line");
        }

        // Send HTTP status code
        http_response_code($this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $value) {
            header("$name: $value", true);
        }

        // Send cookies
        foreach ($this->cookies as $name => $options) {
            setcookie(
                $name,
                $options['value'],
                [
                    'expires' => $options['expires'],
                    'path' => $options['path'],
                    'domain' => $options['domain'],
                    'secure' => $options['secure'],
                    'httponly' => $options['httpOnly'],
                    'samesite' => $options['sameSite']
                ]
            );
        }

        // Send content
        if ($this->content !== null) {
            echo $this->content;
        }

        // Optional: flush output buffers before finishing request
        if (ob_get_level() > 0) {
            ob_end_flush();
            flush();
        }

        // Terminate script execution if in FastCGI environment
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}