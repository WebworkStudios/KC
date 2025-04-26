<?php


namespace Src\Http;

use Attribute;

/**
 * Route-Attribut zum Definieren von HTTP-Routen für Action-Klassen
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Route
{
    /**
     * Erstellt eine neue Route
     *
     * @param string $path URL-Pfad der Route (z.B. "/users" oder "/users/{id}")
     * @param string|array $methods HTTP-Methode(n) (GET, POST, PUT, DELETE, usw.)
     * @param string|null $name Optionaler Name für die Route (für URL-Generierung)
     * @param array $middleware Middleware-Klassen, die vor der Action ausgeführt werden sollen
     */
    public function __construct(
        public readonly string       $path,
        public readonly string|array $methods = ['GET'],
        public readonly ?string      $name = null,
        public readonly array        $middleware = []
    )
    {
    }
}