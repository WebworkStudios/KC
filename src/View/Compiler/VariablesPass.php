<?php

declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Verbesserte Version des VariablesPass
 * Mit spezifischen Fixes für Funktionsaufrufe und String-Literale
 */
class VariablesPass extends AbstractCompilerPass
{
    /**
     * {@inheritDoc}
     */
    protected int $priority = 80;

    /**
     * {@inheritDoc}
     */
    protected string $name = 'Variables';

    /**
     * {@inheritDoc}
     */
    public function process(string $code, array $context = []): string
    {
        // Escaped-Variablen ({{ var }})
        $code = preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/',
            function ($matches) {
                $expression = $matches[1];

                // Fix für Template-spezifische Syntax
                $expression = $this->fixTemplateExpressions($expression);

                // Variablen umwandeln
                $expression = $this->transformVariables($expression);

                return '<?php echo e(' . $this->sanitizeExpression($expression) . '); ?>';
            },
            $code
        );

        // Unescaped-Variablen ({!! var !!})
        $code = preg_replace_callback(
            '/\{!!\s*(.*?)\s*!!\}/',
            function ($matches) {
                $expression = $matches[1];

                // Fix für Template-spezifische Syntax
                $expression = $this->fixTemplateExpressions($expression);

                // Variablen umwandeln
                $expression = $this->transformVariables($expression);

                return '<?php echo ' . $this->sanitizeExpression($expression) . '; ?>';
            },
            $code
        );

        return $code;
    }

    /**
     * Behebt Probleme mit Template-spezifischer Syntax
     *
     * @param string $expression Der zu korrigierende Ausdruck
     * @return string Der korrigierte Ausdruck
     */
    private function fixTemplateExpressions(string $expression): string
    {
        // Bereinige Dollarzeichen in String-Literalen
        $expression = preg_replace_callback(
            '/(["\'])\$([\w\.]+)\\1/',
            function ($matches) {
                return $matches[1] . $matches[2] . $matches[1];
            },
            $expression
        );

        // Bereinige Funktionsaufrufe
        $expression = preg_replace_callback(
            '/url\([\'"]?([\w\.]+)[\'"]?/',
            function ($matches) {
                return 'url(\'' . $matches[1] . '\'';
            },
            $expression
        );

        // Bereinige Array-Syntax
        $expression = preg_replace(
            '/\{\'(\w+)\'\s*:\s*/',
            "['" . '$1' . "' => ",
            $expression
        );

        // Schließende geschweifte Klammer zu eckiger Klammer
        $expression = str_replace('}', ']', $expression);

        return $expression;
    }
}