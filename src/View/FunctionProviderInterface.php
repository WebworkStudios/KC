<?php
namespace Src\View;

/**
 * Interface für Provider von Template-Hilfsfunktionen
 */
interface FunctionProviderInterface
{
    /**
     * Gibt alle registrierbaren Funktionen zurück
     *
     * @return array Array mit Funktionsnamen als Schlüssel und Callbacks als Werte
     */
    public function getFunctions(): array;
}