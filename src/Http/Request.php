<?php


namespace Src\Http;

/**
 * Repräsentiert eine HTTP-Anfrage
 */
class Request
{
    /** @var array Anfrageparameter aus dem Routing */
    private array $routeParameters = [];

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
        return $this->server['REQUEST_METHOD'] ?? 'GET';
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
     * Setzt einen Route-Parameter
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
}