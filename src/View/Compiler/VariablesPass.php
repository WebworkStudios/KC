<?php

declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Compiler-Pass für Variablen ({{ var }}, {!! var !!})
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
                $expression = $this->transformVariables($matches[1]);
                return '<?php echo e(' . $this->sanitizeExpression($expression) . '); ?>';
            },
            $code
        );

        // Unescaped-Variablen ({!! var !!})
        $code = preg_replace_callback(
            '/\{!!\s*(.*?)\s*!!\}/',
            function ($matches) {
                $expression = $this->transformVariables($matches[1]);
                return '<?php echo ' . $this->sanitizeExpression($expression) . '; ?>';
            },
            $code
        );

        return $code;
    }
}