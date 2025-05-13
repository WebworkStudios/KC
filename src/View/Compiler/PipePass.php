<?php

declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Compiler-Pass für Pipe-Operationen (var|filter1|filter2)
 */
class PipePass extends AbstractCompilerPass
{
    /**
     * {@inheritDoc}
     */
    protected int $priority = 70;

    /**
     * {@inheritDoc}
     */
    protected string $name = 'Pipes';

    /**
     * {@inheritDoc}
     */
    public function process(string $code, array $context = []): string
    {
        // Pipes in Variablen ({{ var|filter1|filter2 }}) finden und verarbeiten
        $code = preg_replace_callback(
            '/\{\{\s*(.*?\|.*?)\s*\}\}/',
            function ($matches) {
                $processedExpression = $this->processPipeExpression($matches[1]);
                return '{{ ' . $processedExpression . ' }}';
            },
            $code
        );

        // Pipes in unescaped Variablen ({!! var|filter1|filter2 !!}) finden und verarbeiten
        $code = preg_replace_callback(
            '/\{!!\s*(.*?\|.*?)\s*!!\}/',
            function ($matches) {
                $processedExpression = $this->processPipeExpression($matches[1]);
                return '{!! ' . $processedExpression . ' !!}';
            },
            $code
        );

        return $code;
    }

    /**
     * Verarbeitet einen Pipe-Ausdruck
     *
     * @param string $expression Pipe-Ausdruck (z.B. "var|filter1:arg1,arg2|filter2")
     * @return string Verarbeiteter Ausdruck (z.B. "filter2(filter1(var, 'arg1', 'arg2'))")
     */
    private function processPipeExpression(string $expression): string
    {
        $parts = explode('|', $expression);
        $base = trim(array_shift($parts));

        // Basis-Variable mit $ präfixieren, wenn es ein einfacher Variablenname ist
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $base)) {
            $base = '$' . $base;
        } else {
            $base = $this->transformVariables($base);
        }

        foreach ($parts as $part) {
            $part = trim($part);

            // Filter mit Argumenten (filter:arg1,arg2)
            if (strpos($part, ':') !== false) {
                list($filter, $args) = explode(':', $part, 2);
                $filter = trim($filter);

                // Argumente verarbeiten
                $argArray = [];
                foreach (explode(',', $args) as $arg) {
                    $arg = trim($arg);

                    // Variablen-Argument
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $arg)) {
                        $argArray[] = '$' . $arg;
                    }
                    // String-Argument
                    elseif (preg_match('/^[\'"].*[\'"]$/', $arg)) {
                        $argArray[] = $arg;
                    }
                    // Numerisches oder anderes Argument
                    else {
                        $argArray[] = $this->transformVariables($arg);
                    }
                }

                // Filter-Aufruf erstellen
                $args = implode(', ', $argArray);
                $base = "$filter($base, $args)";
            } else {
                $base = "$part($base)";
            }
        }

        return $base;
    }
}