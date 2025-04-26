<?php


namespace Src\Http;

/**
 * Repräsentiert eine HTTP-Antwort
 */
class Response
{
    /** @var array HTTP-Header */
    private array $headers = [];

    /**
     * Erstellt eine neue Response-Instanz
     *
     * @param string $content Inhalt der Antwort
     * @param int $status HTTP-Statuscode
     * @param string $contentType Content-Type-Header
     */
    public function __construct(
        private string $content = '',
        private int    $status = 200,
        string         $contentType = 'text/html; charset=UTF-8'
    )
    {
        $this->setHeader('Content-Type', $contentType);
    }

    /**
     * Setzt einen HTTP-Header
     *
     * @param string $name Header-Name
     * @param string $value Header-Wert
     * @return self
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Erstellt eine JSON-Response
     *
     * @param mixed $data Zu serialisierende Daten
     * @param int $status HTTP-Statuscode
     * @return self
     */
    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            'application/json; charset=UTF-8'
        );
    }

    /**
     * Erstellt eine Redirect-Response
     *
     * @param string $url Ziel-URL
     * @param int $status HTTP-Statuscode (301 oder 302)
     * @return self
     */
    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self('', $status);
        $response->setHeader('Location', $url);
        return $response;
    }

    /**
     * Sendet die Antwort an den Client
     *
     * @return void
     */
    public function send(): void
    {
        // HTTP-Status setzen
        http_response_code($this->status);

        // Header senden
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        // Inhalt ausgeben
        echo $this->content;
    }

    /**
     * Gibt den Inhalt der Antwort zurück
     *
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Gibt den HTTP-Statuscode zurück
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Gibt alle HTTP-Header zurück
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}