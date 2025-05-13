<?php

declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Compiler-Pass für Include-Direktiven (include)
 */
class IncludePass extends AbstractCompilerPass
{
    /**
     * {@inheritDoc}
     */
    protected int $priority = 40;

    /**
     * {@inheritDoc}
     */
    protected string $name = 'Includes';

    /**
     * {@inheritDoc}
     */
    public function process(string $code, array $context = []): string
    {
        // {% include 'partial' %}
        $code = preg_replace_callback(
            '/{%\s*include\s+(["\'])(.*?)\1(?:\s+with\s+(.*?))?\s*%}/s',
            function ($matches) {
                $template = $matches[2];
                $params = isset($matches[3]) ? $this->parseIncludeParams($matches[3]) : 'get_defined_vars()';

                return '<?php echo $this->includeTemplate("' . $template . '", ' . $params . '); ?>';
            },
            $code
        );

        return $code;
    }

    /**
     * Parst die Include-Parameter
     *
     * @param string $paramsString String mit Parametern
     * @return string PHP-Array-Code
     */
    private function parseIncludeParams(string $paramsString): string
    {
        $params = [];
        $matches = [];

        // Parameter im Format 'key': value oder "key": value
        preg_match_all('/([\'"])(.*?)\1\s*:\s*(.*?)(?:,|$)/', $paramsString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match[2];
            $value = $this->transformVariables(trim($match[3]));
            $params[] = "'" . $key . "' => " . $value;
        }

        // Wenn Parameter vorhanden sind, als Array zurückgeben, sonst alle Variablen einbinden
        return !empty($params)
            ? 'array_merge(get_defined_vars(), [' . implode(', ', $params) . '])'
            : 'get_defined_vars()';
    }
}