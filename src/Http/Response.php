<?php

namespace Src\Http;

/**
 * Repräsentiert eine HTTP-Antwort
 */
class Response
{
    /** @var array HTTP-Header */
    private array $headers = [];

    /** @var array Gültige HTTP-Status-Codes */
    private const VALID_STATUS_CODES = [
        // 1xx - Informational
        100, 101, 102, 103,
        // 2xx - Success
        200, 201, 202, 203, 204, 205, 206, 207, 208, 226,
        // 3xx - Redirection
        300, 301, 302, 303, 304, 305, 306, 307, 308,
        // 4xx - Client Error
        400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413, 414, 415, 416, 417, 418,
        421, 422, 423, 424, 425, 426, 428, 429, 431, 451,
        // 5xx - Server Error
        500, 501, 502, 503, 504, 505, 506, 507, 508, 510, 511
    ];

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
        // Status-Code validieren
        $this->validateStatusCode($status);

        $this->setHeader('Content-Type', $contentType);
    }

    /**
     * Validiert den HTTP-Status-Code
     *
     * @param int $statusCode Status-Code
     * @throws \InvalidArgumentException Wenn der Status-Code ungültig ist
     */
    private function validateStatusCode(int $statusCode): void
    {
        if (!in_array($statusCode, self::VALID_STATUS_CODES)) {
            throw new \InvalidArgumentException("Ungültiger HTTP-Status-Code: $statusCode");
        }
    }

    /**
     * Erstellt eine neue Response mit geändertem Content (immutable)
     *
     * @param string $content Neuer Inhalt
     * @return self Neue Response-Instanz
     */
    public function withContent(string $content): self
    {
        $clone = clone $this;
        $clone->content = $content;
        return $clone;
    }

    /**
     * Erstellt eine neue Response mit geändertem Status (immutable)
     *
     * @param int $status Neuer HTTP-Status-Code
     * @return self Neue Response-Instanz
     */
    public function withStatus(int $status): self
    {
        $this->validateStatusCode($status);

        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    /**
     * Setzt einen HTTP-Header (mutable)
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
     * Erstellt eine neue Response mit geändertem Header (immutable)
     *
     * @param string $name Header-Name
     * @param string $value Header-Wert
     * @return self Neue Response-Instanz
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /**
     * Erstellt eine neue Response mit hinzugefügtem Header (immutable)
     * Existierende Header werden beibehalten, mehrere Werte durch Komma getrennt
     *
     * @param string $name Header-Name
     * @param string $value Header-Wert
     * @return self Neue Response-Instanz
     */
    public function withAddedHeader(string $name, string $value): self
    {
        $clone = clone $this;

        if (isset($clone->headers[$name])) {
            $clone->headers[$name] .= ', ' . $value;
        } else {
            $clone->headers[$name] = $value;
        }

        return $clone;
    }

    /**
     * Erstellt eine neue Response ohne einen bestimmten Header (immutable)
     *
     * @param string $name Header-Name
     * @return self Neue Response-Instanz
     */
    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        unset($clone->headers[$name]);
        return $clone;
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
     * Erstellt eine HTML-Response
     *
     * @param string $html HTML-Inhalt
     * @param int $status HTTP-Statuscode
     * @return self
     */
    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, 'text/html; charset=UTF-8');
    }

    /**
     * Erstellt eine Text-Response
     *
     * @param string $text Text-Inhalt
     * @param int $status HTTP-Statuscode
     * @return self
     */
    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, 'text/plain; charset=UTF-8');
    }

    /**
     * Erstellt eine XML-Response
     *
     * @param string $xml XML-Inhalt
     * @param int $status HTTP-Statuscode
     * @return self
     */
    public static function xml(string $xml, int $status = 200): self
    {
        return new self($xml, $status, 'application/xml; charset=UTF-8');
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
        if ($status !== 301 && $status !== 302 && $status !== 303 && $status !== 307 && $status !== 308) {
            throw new \InvalidArgumentException("Ungültiger Redirect-Status-Code: $status");
        }

        $response = new self('', $status);
        $response->setHeader('Location', $url);
        return $response;
    }

    /**
     * Erstellt eine Not Found (404) Response
     *
     * @param string $message Nachricht
     * @return self
     */
    public static function notFound(string $message = 'Not Found'): self
    {
        return new self($message, 404);
    }

    /**
     * Erstellt eine Bad Request (400) Response
     *
     * @param string $message Nachricht
     * @return self
     */
    public static function badRequest(string $message = 'Bad Request'): self
    {
        return new self($message, 400);
    }

    /**
     * Erstellt eine Forbidden (403) Response
     *
     * @param string $message Nachricht
     * @return self
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self($message, 403);
    }

    /**
     * Erstellt eine Server Error (500) Response
     *
     * @param string $message Nachricht
     * @return self
     */
    public static function serverError(string $message = 'Internal Server Error'): self
    {
        return new self($message, 500);
    }

    /**
     * Erstellt eine Response mit CORS-Headern
     *
     * @param string $origin Erlaubte Herkunft (*, oder spezifische Domain)
     * @param array $methods Erlaubte HTTP-Methoden
     * @param array $headers Erlaubte HTTP-Header
     * @param bool $credentials Ob Credentials erlaubt sind
     * @param int $maxAge Maximale Cache-Zeit für Preflight-Anfragen
     * @return self
     */
    public function withCors(
        string $origin = '*',
        array $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        array $headers = ['Content-Type', 'Authorization'],
        bool $credentials = false,
        int $maxAge = 86400
    ): self {
        $clone = clone $this;

        $clone->headers['Access-Control-Allow-Origin'] = $origin;
        $clone->headers['Access-Control-Allow-Methods'] = implode(', ', $methods);
        $clone->headers['Access-Control-Allow-Headers'] = implode(', ', $headers);
        $clone->headers['Access-Control-Max-Age'] = (string)$maxAge;

        if ($credentials) {
            $clone->headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $clone;
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

        // Content-Length-Header setzen, wenn nicht bereits vorhanden
        if (!isset($this->headers['Content-Length']) && !empty($this->content)) {
            $this->headers['Content-Length'] = (string)strlen($this->content);
        }

        // Header senden
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        // Inhalt ausgeben
        echo $this->content;
    }

    /**
     * Sendet den Inhalt als Stream (für große Dateien)
     *
     * @param int $bufferSize Puffergröße in Bytes
     * @return void
     */
    public function sendAsStream(int $bufferSize = 8192): void
    {
        // HTTP-Status setzen
        http_response_code($this->status);

        // Header senden (ohne Content-Length, da wir streamen)
        foreach ($this->headers as $name => $value) {
            if ($name !== 'Content-Length') {
                header("$name: $value");
            }
        }

        // Buffer-Größe für Output setzen
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Ausgabepuffer ausschalten und in Chunks senden
        $length = strlen($this->content);
        $start = 0;

        while ($start < $length) {
            $chunk = substr($this->content, $start, $bufferSize);
            echo $chunk;

            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }

            $start += $bufferSize;

            // Puffer leeren und zum Client senden
            ob_flush();
            flush();
        }
    }

    /**
     * Sendet eine Datei als Response
     *
     * @param string $filePath Pfad zur Datei
     * @param string|null $filename Download-Dateiname (oder null für inline)
     * @param string|null $contentType Content-Type oder null für automatische Erkennung
     * @return void
     */
    public function sendFile(string $filePath, ?string $filename = null, ?string $contentType = null): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        // HTTP-Status setzen
        http_response_code($this->status);

        // Content-Type ermitteln, falls nicht angegeben
        if ($contentType === null) {
            $contentType = mime_content_type($filePath) ?: 'application/octet-stream';
        }

        // Header setzen
        header("Content-Type: $contentType");
        header("Content-Length: " . filesize($filePath));

        // Content-Disposition für Downloads
        if ($filename !== null) {
            $safeFilename = str_replace('"', '', $filename);
            header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        } else {
            header('Content-Disposition: inline');
        }

        // Andere Standard-Header überschreiben
        foreach ($this->headers as $name => $value) {
            if ($name !== 'Content-Type' && $name !== 'Content-Length' && $name !== 'Content-Disposition') {
                header("$name: $value");
            }
        }

        // Output-Buffer leeren
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Datei ausgeben
        readfile($filePath);
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

    /**
     * Prüft, ob ein Header existiert
     *
     * @param string $name Header-Name
     * @return bool
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    /**
     * Gibt einen Header zurück
     *
     * @param string $name Header-Name
     * @param string|null $default Standardwert, falls Header nicht existiert
     * @return string|null
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headers[$name] ?? $default;
    }
}