<?php

namespace Src\Http;

class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private ?string $content = null;
    private array $cookies = [];
    private string $contentType = 'text/html';
    private string $charset = 'UTF-8';

    /**
     * Create a new response instance
     */
    public function __construct(?string $content = null, int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;

        // Set default content type header
        $this->setHeader('Content-Type', $this->contentType . '; charset=' . $this->charset);
    }

    /**
     * Set response status code
     */
    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Get response status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Set response content
     */
    public function setContent(?string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Get response content
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * Set a response header
     */
    public function setHeader(string $name, $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set multiple headers
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
     */
    public function getHeaders(): array
    {
        return $this->headers;
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

        // Only add charset for text-based content types
        if (str_starts_with($contentType, 'text/') ||
            in_array($contentType, ['application/json', 'application/xml', 'application/javascript'], true)) {
            $contentType .= '; charset=' . $this->charset;
        }

        $this->setHeader('Content-Type', $contentType);
        return $this;
    }

    /**
     * Set response as JSON
     *
     * @param mixed $data Data to be encoded as JSON
     * @param int $statusCode HTTP status code
     * @param int $options JSON encoding options
     * @return self Fluent interface
     * @throws \JsonException
     */
    public function json(mixed $data, int $statusCode = 200, int $options = 0): self
    {
        $this->setContentType('application/json');
        $this->setStatusCode($statusCode);

        $jsonContent = json_encode($data, $options);
        if ($jsonContent === false) {
            throw new \JsonException(json_last_error_msg(), json_last_error());
        }

        $this->setContent($jsonContent);
        return $this;
    }

    /**
     * Set response as HTML
     */
    public function html(string $html, int $statusCode = 200): self
    {
        $this->setContentType('text/html');
        $this->setStatusCode($statusCode);
        $this->setContent($html);

        return $this;
    }

    /**
     * Set response as plain text
     */
    public function text(string $text, int $statusCode = 200): self
    {
        $this->setContentType('text/plain');
        $this->setStatusCode($statusCode);
        $this->setContent($text);

        return $this;
    }

    /**
     * Create a redirect response
     */
    public function redirect(string $url, int $statusCode = 302): self
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Location', $url);
        $this->setContent(null);

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
     */
    public function setCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true, // Changed default to true for better security
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
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
     */
    public function removeCookie(string $name, string $path = '/', string $domain = ''): self
    {
        // Set cookie with expiration in the past to remove it
        return $this->setCookie($name, '', time() - 3600, $path, $domain);
    }

    /**
     * Set cache control headers
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
     */
    public function noCache(): self
    {
        $this->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->setHeader('Pragma', 'no-cache');
        $this->setHeader('Expires', '0');

        return $this;
    }

    /**
     * Send HTTP response with improved error handling
     *
     * @throws \RuntimeException If headers have already been sent
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
            ob_flush();
            flush();
        }

        // Terminate script execution if in FastCGI environment
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}