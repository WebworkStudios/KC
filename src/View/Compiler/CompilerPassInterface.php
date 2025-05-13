<?php


declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Interface für Compiler-Passes
 *
 * Ein Compiler-Pass ist für einen bestimmten Aspekt der Template-Kompilierung zuständig.
 */
interface CompilerPassInterface
{
    /**
     * Verarbeitet den Template-Code
     *
     * @param string $code Template-Code
     * @param array<string, mixed> $context Kompilierungskontext
     * @return string Verarbeiteter Code
     */
    public function process(string $code, array $context = []): string;

    /**
     * Gibt die Priorität des Passes zurück
     *
     * Niedrigere Werte bedeuten höhere Priorität/frühere Ausführung.
     *
     * @return int Priorität (0-999)
     */
    public function getPriority(): int;

    /**
     * Gibt den Namen des Passes zurück
     *
     * @return string Name
     */
    public function getName(): string;
}