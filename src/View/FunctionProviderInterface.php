<?php

declare(strict_types=1);

namespace Src\View;

/**
 * Interface für Provider von Template-Hilfsfunktionen
 *
 * Ermöglicht die modulare Registrierung von Hilfsfunktionen in Templates
 */
interface FunctionProviderInterface
{
    /**
     * Gibt alle registrierbaren Funktionen zurück
     *
     * @return array<string, callable> Array mit Funktionsnamen als Schlüssel und Callbacks als Werte
     */
    public function getFunctions(): array;
}