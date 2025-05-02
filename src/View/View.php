<?php

namespace Src\View;

use Src\Http\Response;
use Src\View\Exception\TemplateException;

/**
 * View-Klasse für das Rendering von Templates
 */
class View
{
    /**
     * Erstellt eine neue View-Instanz
     *
     * @param TemplateEngine $engine Template-Engine-Instanz
     * @param string $template Template-Name
     * @param array $data View-Daten
     */
    public function __construct(
        private readonly TemplateEngine $engine,
        private readonly string $template,
        private array $data = []
    ) {
    }

    /**
     * Fügt Daten zur View hinzu
     *
     * @param string|array $key Schlüssel oder assoziatives Array
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
     * Rendert das Template
     *
     * @return string Gerendertes Template
     * @throws TemplateException Bei Fehlern im Template
     */
    public function render(): string
    {
        return $this->engine->render($this->template, $this->data);
    }

    /**
     * Konvertiert die View in eine HTTP-Response
     *
     * @param int $status HTTP-Statuscode
     * @return Response HTTP-Response
     */
    public function toResponse(int $status = 200): Response
    {
        return new Response($this->render(), $status, 'text/html; charset=UTF-8');
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
     * @return array View-Daten
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Magische Methode für die String-Konvertierung
     *
     * @return string Gerendertes Template
     */
    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (\Throwable $e) {
            // Fehler protokollieren statt trigger_error
            error_log('View Rendering Error: ' . $e->getMessage());
            return 'Error rendering view: ' . $e->getMessage();
        }
    }

    /**
     * Legt das Layout für die View fest
     *
     * @param string $layout Layout-Name
     * @return self
     */
    public function layout(string $layout): self
    {
        $this->engine->layout($layout);
        return $this;
    }
}