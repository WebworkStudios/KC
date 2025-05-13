<?php

declare(strict_types=1);

namespace Src\View;

use Src\Http\Response;
use Src\View\Exception\TemplateException;

/**
 * View-Klasse für das Rendering von Templates
 */
class View
{
    /**
     * Template-Engine
     *
     * @var TemplateEngine
     */
    private TemplateEngine $engine;

    /**
     * Template-Name
     *
     * @var string
     */
    private string $template;

    /**
     * View-Daten
     *
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * Erstellt eine neue View
     *
     * @param TemplateEngine $engine Template-Engine
     * @param string $template Template-Name
     * @param array<string, mixed> $data View-Daten
     */
    public function __construct(TemplateEngine $engine, string $template, array $data = [])
    {
        $this->engine = $engine;
        $this->template = $template;
        $this->data = $data;
    }

    /**
     * Fügt Daten zur View hinzu
     *
     * @param string|array<string, mixed> $key Schlüssel oder assoziatives Array
     * @param mixed $value Wert (wenn $key ein String ist)
     * @return self
     */
    public function with(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }

        return $this;
    }

    /**
     * Gibt den Template-Namen zurück
     *
     * @return string Template-Name
     */
    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * Gibt die View-Daten zurück
     *
     * @return array<string, mixed> View-Daten
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Legt das Layout für die View fest
     *
     * @param string|null $layout Layout-Name
     * @return self
     */
    public function layout(?string $layout): self
    {
        $this->engine->layout($layout);
        return $this;
    }

    /**
     * Rendert die View
     *
     * @return string Gerenderter Inhalt
     * @throws TemplateException Bei Fehlern im Template
     */
    public function render(): string
    {
        return $this->engine->render($this->template, $this->data);
    }

    /**
     * Erstellt eine HTTP-Response aus der View
     *
     * @param int $status HTTP-Statuscode
     * @param array<string, string> $headers HTTP-Header
     * @return Response HTTP-Response
     */
    public function toResponse(int $status = 200, array $headers = []): Response
    {
        // Content-Type standardmäßig setzen, wenn nicht vorhanden
        $hasContentType = false;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $hasContentType = true;
                break;
            }
        }

        if (!$hasContentType) {
            $headers['Content-Type'] = 'text/html; charset=UTF-8';
        }

        return new Response($this->render(), $status, $headers);
    }

    /**
     * Erstellt eine JSON-Response aus den View-Daten
     *
     * @param int $status HTTP-Statuscode
     * @param int $options JSON-Optionen
     * @return Response HTTP-Response
     */
    public function toJson(int $status = 200, int $options = 0): Response
    {
        $json = json_encode($this->data, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new TemplateException('Failed to encode view data as JSON: ' . json_last_error_msg());
        }

        return new Response($json, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * Fügt einen Output-Filter hinzu
     *
     * @param callable $filter Filter-Funktion
     * @return self
     */
    public function addOutputFilter(callable $filter): self
    {
        $this->engine->addOutputFilter($filter);
        return $this;
    }

    /**
     * Magische Methode für String-Konvertierung
     *
     * @return string Gerenderter Inhalt
     */
    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (\Throwable $e) {
            // Fehler protokollieren
            error_log('View rendering error: ' . $e->getMessage());

            // Sichere Fehleranzeige
            return 'Error rendering view: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}