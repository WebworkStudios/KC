<?php
declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Compiler-Pass für direktive Anweisungen wie isset, empty, php, etc.
 */
class DirectivesPass extends AbstractCompilerPass
{
    /**
     * {@inheritDoc}
     */
    protected int $priority = 50;

    /**
     * {@inheritDoc}
     */
    protected string $name = 'Directives';

    /**
     * {@inheritDoc}
     */
    public function process(string $code, array $context = []): string
    {
        // {% isset variable %}
        $code = preg_replace_callback(
            '/{%\s*isset\s+(.*?)\s*%}/s',
            function ($matches) {
                $variable = $this->transformVariables($matches[1]);
                return '<?php if(isset(' . $variable . ')): ?>';
            },
            $code
        );

        // {% endisset %}
        $code = preg_replace(
            '/{%\s*endisset\s*%}/s',
            '<?php endif; ?>',
            $code
        );

        // {% empty variable %}
        $code = preg_replace_callback(
            '/{%\s*empty\s+(.*?)\s*%}/s',
            function ($matches) {
                $variable = $this->transformVariables($matches[1]);
                return '<?php if(empty(' . $variable . ')): ?>';
            },
            $code
        );

        // {% endempty %}
        $code = preg_replace(
            '/{%\s*endempty\s*%}/s',
            '<?php endif; ?>',
            $code
        );

        // {% php %}...{% endphp %}
        $code = preg_replace_callback(
            '/{%\s*php\s*%}(.*?){%\s*endphp\s*%}/s',
            function ($matches) {
                return '<?php ' . $matches[1] . ' ?>';
            },
            $code
        );

        // {% call macro.name(args) %}
        $code = preg_replace_callback(
            '/{%\s*call\s+(.*?)\s*%}/s',
            function ($matches) {
                $call = $this->sanitizeExpression($matches[1]);
                return '<?php echo ' . $call . '; ?>';
            },
            $code
        );

        return $code;
    }
}