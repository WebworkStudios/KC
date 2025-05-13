<?php
declare(strict_types=1);

namespace Src\View\Compiler;

use Src\View\Exception\TemplateException;
use Throwable;

/**
 * Template-Compiler
 *
 * Kompiliert Templates in ausführbaren PHP-Code
 */
class TemplateCompiler
{
    /**
     * Registrierte Compiler-Passes
     *
     * @var array<CompilerPassInterface>
     */
    private array $passes = [];

    /**
     * Erstellt einen neuen TemplateCompiler mit Standard-Passes
     */
    public function __construct()
    {
        $this->registerDefaultPasses();
    }

    /**
     * Registriert die Standard-Compiler-Passes
     *
     * @return void
     */
    private function registerDefaultPasses(): void
    {
        $this->registerPass(new CommentPass());
        $this->registerPass(new LayoutPass());
        $this->registerPass(new ComponentPass());
        $this->registerPass(new IncludePass());
        $this->registerPass(new DirectivesPass());
        $this->registerPass(new ControlStructuresPass());
        $this->registerPass(new PipePass());
        $this->registerPass(new VariablesPass());
    }

    /**
     * Registriert einen Compiler-Pass
     *
     * @param CompilerPassInterface $pass Compiler-Pass
     * @return self
     */
    public function registerPass(CompilerPassInterface $pass): self
    {
        $this->passes[] = $pass;
        return $this;
    }

    /**
     * Kompiliert ein Template
     *
     * @param string $code Template-Code
     * @param string $name Template-Name (für Fehlermeldungen)
     * @param array<string, mixed> $context Zusätzlicher Kompilierungskontext
     * @return string Kompilierter PHP-Code
     * @throws TemplateException Wenn bei der Kompilierung ein Fehler auftritt
     */
    public function compile(string $code, string $name, array $context = []): string
    {
        try {
            // PHP-Header für das kompilierte Template
            $compiledCode = '<?php /* Compiled template: ' . $name . ' */ ?>' . PHP_EOL;

            // Sicherheits-Header
            $compiledCode .= '<?php if (!function_exists("e")) { ';
            $compiledCode .= 'function e($expression) { return htmlspecialchars((string)$expression, ENT_QUOTES, "UTF-8", false); }';
            $compiledCode .= '} ?>' . PHP_EOL;

            // Kompilierungs-Kontext erweitern
            $context['template_name'] = $name;

            // Sortiere Passes nach Priorität
            $passes = $this->passes;
            usort($passes, function (CompilerPassInterface $a, CompilerPassInterface $b) {
                return $a->getPriority() <=> $b->getPriority();
            });

            // Führe alle Passes aus
            foreach ($passes as $pass) {
                $code = $pass->process($code, $context);
            }

            // Kompilierten Code zurückgeben
            return $compiledCode . $code;
        } catch (Throwable $e) {
            throw TemplateException::compilationError($e->getMessage(), $name, $e);
        }
    }

    /**
     * Gibt alle registrierten Passes zurück
     *
     * @return array<CompilerPassInterface>
     */
    public function getPasses(): array
    {
        return $this->passes;
    }

    /**
     * Entfernt alle registrierten Passes
     *
     * @return self
     */
    public function clearPasses(): self
    {
        $this->passes = [];
        return $this;
    }
}