<?php


namespace Src\View;

use Src\Http\Response;
use Src\Http\Router;
use Src\View\Exception\TemplateException;

/**
 * Factory-Klasse für die Erstellung von Views
 */
class ViewFactory
{
    /** @var TemplateEngine Template-Engine-Instanz */
    private TemplateEngine $engine;

    /** @var array Globale View-Daten */
    private array $globalData = [];

    /**
     * Erstellt eine neue ViewFactory-Instanz
     *
     * @param TemplateEngine $engine Template-Engine-Instanz
     */
    public function __construct(TemplateEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Erstellt eine View-Instanz
     *
     * @param string $template Template-Name
     * @param array $data View-Daten
     * @return View View-Instanz
     */
    public function make(string $template, array $data = []): View
    {
        return new View($this->engine, $template, array_merge($this->globalData, $data));
    }

    /**
     * Rendert ein Template direkt zu einer HTTP-Response
     *
     * @param string $template Template-Name
     * @param array $data View-Daten
     * @param int $status HTTP-Statuscode
     * @return Response HTTP-Response
     */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        $view = $this->make($template, $data);
        return $view->toResponse($status);
    }

    /**
     * Registriert globale View-Daten
     *
     * @param string|array $key Schlüssel oder assoziatives Array
     * @param mixed $value Wert (wenn $key ein String ist)
     * @return self
     */
    public function share(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->globalData = array_merge($this->globalData, $key);
        } else {
            $this->globalData[$key] = $value;
        }

        return $this;
    }

    /**
     * Registriert einen Function-Provider für die Template-Engine
     *
     * @param FunctionProviderInterface $provider Function-Provider
     * @return self
     */
    public function registerFunctionProvider(FunctionProviderInterface $provider): self
    {
        $this->engine->registerFunctionProvider($provider);
        return $this;
    }

    /**
     * Registriert eine einzelne Hilfsfunktion
     *
     * @param string $name Funktionsname
     * @param callable $callback Callback-Funktion
     * @return self
     */
    public function registerFunction(string $name, callable $callback): self
    {
        $this->engine->registerFunction($name, $callback);
        return $this;
    }

    /**
     * Setzt den Router für die URL-Generierung
     *
     * @param Router $router Router-Instanz
     * @return self
     */
    public function setRouter(Router $router): self
    {
        $this->engine->setRouter($router);
        return $this;
    }

    /**
     * Löscht den Template-Cache
     *
     * @return bool True bei Erfolg
     */
    public function clearCache(): bool
    {
        return $this->engine->clearCache();
    }

    /**
     * Konfiguriert das Layout für die nächsten Views
     *
     * @param string|null $layout Layout-Name oder null für kein Layout
     * @return self
     */
    public function layout(?string $layout): self
    {
        $this->engine->layout($layout);
        return $this;
    }

    /**
     * Gibt die Template-Engine-Instanz zurück
     *
     * @return TemplateEngine
     */
    public function getEngine(): TemplateEngine
    {
        return $this->engine;
    }
}