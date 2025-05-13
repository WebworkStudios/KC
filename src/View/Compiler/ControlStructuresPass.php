<?php

declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Compiler-Pass für Kontrollstrukturen (if, foreach, for, etc.)
 * Mit Unterstützung für spezielle Bedingungsausdrücke wie "is null", "is not null", etc.
 */
class ControlStructuresPass extends AbstractCompilerPass
{
    /**
     * {@inheritDoc}
     */
    protected int $priority = 60;

    /**
     * {@inheritDoc}
     */
    protected string $name = 'ControlStructures';

    /**
     * {@inheritDoc}
     */
    public function process(string $code, array $context = []): string
    {
        // {% if condition %}
        $code = preg_replace_callback(
            '/{%\s*if\s+(.*?)\s*%}/s',
            function ($matches) {
                // Spezielle Bedingungen verarbeiten
                $condition = $this->processSpecialConditions($matches[1]);

                // Variablen transformieren
                $condition = $this->transformVariables($condition);

                // Pipe-Operatoren für length usw. verarbeiten
                $condition = $this->processPipeOperators($condition);

                return '<?php if(' . $this->sanitizeExpression($condition) . '): ?>';
            },
            $code
        );

        // {% elseif condition %}
        $code = preg_replace_callback(
            '/{%\s*elseif\s+(.*?)\s*%}/s',
            function ($matches) {
                // Spezielle Bedingungen verarbeiten
                $condition = $this->processSpecialConditions($matches[1]);

                // Variablen transformieren
                $condition = $this->transformVariables($condition);

                // Pipe-Operatoren für length usw. verarbeiten
                $condition = $this->processPipeOperators($condition);

                return '<?php elseif(' . $this->sanitizeExpression($condition) . '): ?>';
            },
            $code
        );

        // {% else %}
        $code = preg_replace(
            '/{%\s*else\s*%}/s',
            '<?php else: ?>',
            $code
        );

        // {% endif %}
        $code = preg_replace(
            '/{%\s*endif\s*%}/s',
            '<?php endif; ?>',
            $code
        );

        // {% foreach collection as [key =>] value %}
        $code = preg_replace_callback(
            '/{%\s*foreach\s+(.*?)\s+as\s+(?:(\w+)\s*=>\s*)?(\w+)\s*%}/s',
            function ($matches) {
                $collection = $this->transformVariables($matches[1]);
                $collection = $this->sanitizeExpression($collection);

                // Der Key ist optional
                if (isset($matches[2]) && !empty($matches[2])) {
                    $keyVar = '$' . $matches[2] . ' => ';
                } else {
                    $keyVar = ''; // Wenn kein Key angegeben ist, einfach weglassen
                }

                $valueVar = '$' . $matches[3];

                return '<?php if (!empty(' . $collection . ') && (is_array(' . $collection . ') || ' . $collection . ' instanceof \Traversable)): foreach(' . $collection . ' as ' . $keyVar . $valueVar . '): ?>';
            },
            $code
        );

        // {% endforeach %}
        $code = preg_replace(
            '/{%\s*endforeach\s*%}/s',
            '<?php endforeach; endif; ?>',
            $code
        );

        // {% for i in range %}
        $code = preg_replace_callback(
            '/{%\s*for\s+(\w+)\s+in\s+(.*?)\s*%}/s',
            function ($matches) {
                $var = '$' . $matches[1];
                $range = $this->parseRange($matches[2]);

                return '<?php foreach(' . $range . ' as ' . $var . '): ?>';
            },
            $code
        );

        // {% endfor %}
        $code = preg_replace(
            '/{%\s*endfor\s*%}/s',
            '<?php endforeach; ?>',
            $code
        );

        return $code;
    }

    /**
     * Verarbeitet spezielle Bedingungsausdrücke wie "is null", "is not null" usw.
     *
     * @param string $condition Bedingung mit möglicherweise speziellen Ausdrücken
     * @return string PHP-kompatible Bedingung
     */
    private function processSpecialConditions(string $condition): string
    {
        // "is not null" in "!== null" umwandeln
        $condition = preg_replace('/(\w+)\s+is\s+not\s+null/i', '$1 !== null', $condition);

        // "is null" in "=== null" umwandeln
        $condition = preg_replace('/(\w+)\s+is\s+null/i', '$1 === null', $condition);

        // "is not empty" in "!empty(...)" umwandeln
        $condition = preg_replace('/(\w+)\s+is\s+not\s+empty/i', '!empty($1)', $condition);

        // "is empty" in "empty(...)" umwandeln
        $condition = preg_replace('/(\w+)\s+is\s+empty/i', 'empty($1)', $condition);

        // "not" in "!" umwandeln
        $condition = preg_replace('/\bnot\s+(\w+)/i', '!$1', $condition);

        // "and" in "&&" umwandeln
        $condition = preg_replace('/\s+and\s+/i', ' && ', $condition);

        // "or" in "||" umwandeln
        $condition = preg_replace('/\s+or\s+/i', ' || ', $condition);

        return $condition;
    }

    /**
     * Verarbeitet Pipe-Operatoren in Bedingungen
     *
     * @param string $condition Bedingung mit möglichen Pipe-Operatoren
     * @return string Bedingung mit übersetzten Pipe-Operatoren
     */
    private function processPipeOperators(string $condition): string
    {
        // $players|length in count($players) umwandeln
        $condition = preg_replace('/\$(\w+)\|\$?length/i', '(!empty($$$1) ? (is_array($$$1) || $$$1 instanceof \Countable ? count($$$1) : 0) : 0)', $condition);

        return $condition;
    }

    /**
     * Parst einen Bereich (1..10, etc.)
     *
     * @param string $range String im Format "start..end"
     * @return string PHP-Range-Code
     */
    private function parseRange(string $range): string
    {
        // Prüfen auf Range-Expression (1..10)
        if (preg_match('/^(.*?)\.\.(.*?)$/', $range, $matches)) {
            $start = $this->transformVariables(trim($matches[1]));
            $end = $this->transformVariables(trim($matches[2]));

            return 'range(' . $start . ', ' . $end . ')';
        }

        // Sonst als normalen Ausdruck behandeln
        return $this->transformVariables($range);
    }
}