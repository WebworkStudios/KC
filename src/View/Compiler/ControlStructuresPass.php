<?php

declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Compiler-Pass für Kontrollstrukturen (if, foreach, for, etc.)
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
                $condition = $this->transformVariables($matches[1]);
                return '<?php if(' . $this->sanitizeExpression($condition) . '): ?>';
            },
            $code
        );

        // {% elseif condition %}
        $code = preg_replace_callback(
            '/{%\s*elseif\s+(.*?)\s*%}/s',
            function ($matches) {
                $condition = $this->transformVariables($matches[1]);
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

                $keyVar = isset($matches[2]) ? '$' . $matches[2] . ' => ' : '';
                $valueVar = '$' . $matches[3];

                return '<?php foreach(' . $collection . ' as ' . $keyVar . $valueVar . '): ?>';
            },
            $code
        );

        // {% endforeach %}
        $code = preg_replace(
            '/{%\s*endforeach\s*%}/s',
            '<?php endforeach; ?>',
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