<?php
declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Compiler-Pass für Komponenten-Direktiven (component, endcomponent)
 */
class ComponentPass extends AbstractCompilerPass
{
    /**
     * {@inheritDoc}
     */
    protected int $priority = 30;

    /**
     * {@inheritDoc}
     */
    protected string $name = 'Components';

    /**
     * {@inheritDoc}
     */
    public function process(string $code, array $context = []): string
    {
        // {% component 'name' with param: value, param2: value2 %}
        $code = preg_replace_callback(
            '/{%\s*component\s+(["\'])(.*?)\1(?:\s+with\s+(.*?))?\s*%}/s',
            function ($matches) {
                $component = $matches[2];
                $params = isset($matches[3]) ? $this->parseComponentParams($matches[3]) : '[]';

                return '<?php $this->startComponent("' . $component . '", ' . $params . '); ?>';
            },
            $code
        );

        // {% endcomponent %}
        $code = preg_replace(
            '/{%\s*endcomponent\s*%}/s',
            '<?php echo $this->endComponent(); ?>',
            $code
        );

        // {% slot 'name' %}
        $code = preg_replace_callback(
            '/{%\s*slot\s+(["\'])(.*?)\1\s*%}/s',
            function ($matches) {
                return '<?php $this->startSlot("' . $matches[2] . '"); ?>';
            },
            $code
        );

        // {% endslot %}
        $code = preg_replace(
            '/{%\s*endslot\s*%}/s',
            '<?php $this->endSlot(); ?>',
            $code
        );

        return $code;
    }

    /**
     * Parst die Komponenten-Parameter
     *
     * @param string $paramsString String mit Parametern
     * @return string PHP-Array-Code
     */
    private function parseComponentParams(string $paramsString): string
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

        return '[' . implode(', ', $params) . ']';
    }
}