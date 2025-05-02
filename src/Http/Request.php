<?php

namespace Src\Http;

/**
 * Repräsentiert eine HTTP-Anfrage
 */
class Request
{
    /** @var array Anfrageparameter aus dem Routing */
    private array $routeParameters = [];

    /** @var array|null Gecachter Anfrage-Body */
    private ?array $parsedBody = null;

    /** @var string[] Gültige HTTP-Methoden */
    private const VALID_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT'];

    /**
     * Erstellt eine neue Request-Instanz
     *
     * @param array $get GET-Parameter
     * @param array $post POST-Parameter
     * @param array $cookies Cookies
     * @param array $files Hochgeladene Dateien
     * @param array $server Server-Variablen
     */
    public function __construct(
        private readonly array $get = [],
        private readonly array $post = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly array $server = []
    )
    {
    }

    /**
     * Erstellt eine Request-Instanz aus globalen Variablen
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        return new self(
            $_GET ?? [],
            $_POST ?? [],
            $_COOKIE ?? [],
            $_FILES ?? [],
            $_SERVER ?? []
        );
    }

    /**
     * Gibt die HTTP-Methode zurück
     *
     * @return string HTTP-Methode (GET, POST, PUT, DELETE, etc.)
     */
    public function getMethod(): string
    {
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';

        // Validiere die Methode gegen gültige HTTP-Methoden
        return in_array($method, self::VALID_METHODS, true) ? $method : 'GET';
    }

    /**
     * Prüft, ob die Anfrage über HTTPS erfolgt
     *
     * @return bool True, wenn die Anfrage über HTTPS erfolgt
     */
    public function isSecure(): bool
    {
        return (
            ($this->server['HTTPS'] ?? null) === 'on' ||
            ($this->server['HTTP_X_FORWARDED_PROTO'] ?? null) === 'https' ||
            ($this->server['REQUEST_SCHEME'] ?? null) === 'https'
        );
    }

    /**
     * Prüft, ob die Anfrage eine AJAX-Anfrage ist
     *
     * @return bool True, wenn es sich um eine AJAX-Anfrage handelt
     */
    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /**
     * Gibt den Anfragepfad zurück
     *
     * @return string Anfragepfad
     */
    public function getPath(): string
    {
        // Bei virtuellen Hosts mit eigener Domain wie kickerscup.local
        // enthält REQUEST_URI den vollständigen Pfad nach der Domain
        $path = $this->server['PATH_INFO'] ?? $this->server['REQUEST_URI'] ?? '/';

        // Query-String entfernen
        if (($pos = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $pos);
        }

        // Script-Name entfernen falls vorhanden (bei nicht optimalen vHost-Konfigurationen)
        $scriptName = $this->server['SCRIPT_NAME'] ?? '/index.php';
        if (strpos($path, $scriptName) === 0) {
            $path = substr($path, strlen($scriptName));
        }

        return $this->normalizePath($path);
    }

    /**
     * Normalisiert einen Pfad
     */
    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * Prüft, ob ein GET-Parameter existiert
     *
     * @param string $key Parameter-Name
     * @return bool
     */
    public function hasQuery(string $key): bool
    {
        return isset($this->get[$key]);
    }

    /**
     * Gibt einen GET-Parameter zurück
     *
     * @param string $key Parameter-Name
     * @param mixed $default Standardwert, falls Parameter nicht existiert
     * @return mixed
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /**
     * Gibt alle GET-Parameter zurück
     *
     * @return array
     */
    public function getAllQuery(): array
    {
        return $this->get;
    }

    /**
     * Prüft, ob ein POST-Parameter existiert
     *
     * @param string $key Parameter-Name
     * @return bool
     */
    public function hasPost(string $key): bool
    {
        return isset($this->post[$key]);
    }

    /**
     * Gibt einen POST-Parameter zurück
     *
     * @param string $key Parameter-Name
     * @param mixed $default Standardwert, falls Parameter nicht existiert
     * @return mixed
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Gibt alle POST-Parameter zurück
     *
     * @return array
     */
    public function getAllPost(): array
    {
        return $this->post;
    }

    /**
     * Prüft, ob eine hochgeladene Datei existiert
     *
     * @param string $key Dateifeld-Name
     * @return bool
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) &&
            is_array($this->files[$key]) &&
            isset($this->files[$key]['error']) &&
            $this->files[$key]['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Gibt Informationen zu einer hochgeladenen Datei zurück
     *
     * @param string $key Dateifeld-Name
     * @param array|null $default Standardwert, falls Datei nicht existiert
     * @return array|null
     */
    public function getFile(string $key, ?array $default = null): ?array
    {
        return $this->hasFile($key) ? $this->files[$key] : $default;
    }

    /**
     * Gibt alle hochgeladenen Dateien zurück
     *
     * @return array
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Prüft, ob die hochgeladene Datei erfolgreich übertragen wurde
     *
     * @param string $key Dateifeld-Name
     * @return bool
     */
    public function isUploadedFileValid(string $key): bool
    {
        if (!$this->hasFile($key)) {
            return false;
        }

        $file = $this->files[$key];
        return isset($file['error']) && $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']);
    }

    /**
     * Gibt den Anfrage-Body zurück, basierend auf dem Content-Type
     *
     * @return array|null Der geparste Body oder null, wenn kein Body vorhanden ist
     */
    public function getBody(): ?array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        // Content-Type der Anfrage ermitteln
        $contentType = $this->getHeader('Content-Type');

        // Body aus php://input lesen
        $rawBody = file_get_contents('php://input');

        if (empty($rawBody)) {
            return $this->parsedBody = null;
        }

        // Body gemäß Content-Type parsen
        if ($contentType && str_contains($contentType, 'application/json')) {
            $this->parsedBody = json_decode($rawBody, true) ?? [];
        } elseif ($contentType && str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($rawBody, $this->parsedBody);
        } else {
            // Standard: POST-Daten zurückgeben
            $this->parsedBody = $this->post;
        }

        return $this->parsedBody;
    }

    /**
     * Prüft, ob ein HTTP-Header existiert
     *
     * @param string $name Header-Name
     * @return bool
     */
    public function hasHeader(string $name): bool
    {
        $headerName = $this->normalizeHeaderName($name);
        return isset($this->server[$headerName]) || isset($this->server['HTTP_' . $headerName]);
    }

    /**
     * Gibt einen HTTP-Header zurück
     *
     * @param string $name Header-Name
     * @param string|null $default Standardwert, falls Header nicht existiert
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        $headerName = $this->normalizeHeaderName($name);

        // Direkte Header-Namen
        if (isset($this->server[$headerName])) {
            return $this->server[$headerName];
        }

        // HTTP_ Prefix für die meisten Header in $_SERVER
        if (isset($this->server['HTTP_' . $headerName])) {
            return $this->server['HTTP_' . $headerName];
        }

        return $default;
    }

    /**
     * Normalisiert einen Header-Namen zum korrekten Format für $_SERVER
     *
     * @param string $name Header-Name
     * @return string Normalisierter Header-Name
     */
    private function normalizeHeaderName(string $name): string
    {
        return str_replace('-', '_', strtoupper($name));
    }

    /**
     * Setzt einen Route-Parameter (mutable Methode)
     *
     * @param string $key Parameter-Name
     * @param mixed $value Parameter-Wert
     * @return void
     */
    public function setRouteParameter(string $key, mixed $value): void
    {
        $this->routeParameters[$key] = $value;
    }

    /**
     * Erstellt eine neue Request-Instanz mit einem geänderten Route-Parameter (immutable Methode)
     *
     * @param string $key Parameter-Name
     * @param mixed $value Parameter-Wert
     * @return self Neue Request-Instanz
     */
    public function withRouteParameter(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->routeParameters[$key] = $value;
        return $clone;
    }

    /**
     * Prüft, ob ein Route-Parameter existiert
     *
     * @param string $key Parameter-Name
     * @return bool
     */
    public function hasRouteParameter(string $key): bool
    {
        return isset($this->routeParameters[$key]);
    }

    /**
     * Gibt einen Route-Parameter zurück
     *
     * @param string $key Parameter-Name
     * @param mixed $default Standardwert, falls Parameter nicht existiert
     * @return mixed
     */
    public function getRouteParameter(string $key, mixed $default = null): mixed
    {
        return $this->routeParameters[$key] ?? $default;
    }

    /**
     * Gibt alle Route-Parameter zurück
     *
     * @return array
     */
    public function getRouteParameters(): array
    {
        return $this->routeParameters;
    }

    /**
     * Gibt einen Server-Parameter zurück
     *
     * @param string $key Parameter-Name
     * @param mixed $default Standardwert, falls Parameter nicht existiert
     * @return mixed
     */
    public function getServerParam(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Gibt alle Server-Parameter zurück
     *
     * @return array
     */
    public function getServer(): array
    {
        return $this->server;
    }

    /**
     * Prüft, ob ein Cookie existiert
     *
     * @param string $name Cookie-Name
     * @return bool
     */
    public function hasCookie(string $name): bool
    {
        return isset($this->cookies[$name]);
    }

    /**
     * Gibt ein Cookie zurück
     *
     * @param string $name Cookie-Name
     * @param mixed $default Standardwert, falls Cookie nicht existiert
     * @return mixed
     */
    public function getCookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * Gibt alle Cookies zurück
     *
     * @return array
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Gibt die Client-IP-Adresse zurück
     *
     * @return string IP-Adresse
     */
    public function getClientIp(): string
    {
        $keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($keys as $key) {
            if (isset($this->server[$key])) {
                $ips = explode(',', $this->server[$key]);
                $ip = trim(current($ips));

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}