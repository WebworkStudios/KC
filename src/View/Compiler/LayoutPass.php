<?php
declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Compiler-Pass für Layout-Direktiven (extends, section, yield)
 */
class LayoutPass extends AbstractCompilerPass
{
    /**
     * {@inheritDoc}
     */
    protected int $priority = 20;

    /**
     * {@inheritDoc}
     */
    protected string $name = 'Layouts';

    /**
     * {@inheritDoc}
     */
    public function process(string $code, array $context = []): string
    {
        // @extends - Layout-Direktive
        $code = preg_replace_callback(
            '/{%\s*extends\s+(["\'])(.*?)\1\s*%}/s',
            function ($matches) {
                return '<?php $this->layout("' . $matches[2] . '"); ?>';
            },
            $code
        );

        // @section - Section-Direktive
        $code = preg_replace_callback(
            '/{%\s*section\s+(["\'])(.*?)\1\s*%}/s',
            function ($matches) {
                return '<?php $this->startSection("' . $matches[2] . '"); ?>';
            },
            $code
        );

        // @endsection - EndSection-Direktive
        $code = preg_replace(
            '/{%\s*endsection\s*%}/s',
            '<?php $this->endSection(); ?>',
            $code
        );

        // @yield - Yield-Direktive
        $code = preg_replace_callback(
            '/{%\s*yield\s+(["\'])(.*?)\1(?:\s+(["\']?)(.*?)\3)?\s*%}/s',
            function ($matches) {
                $name = $matches[2];
                $default = isset($matches[4]) ? ', "' . addslashes($matches[4]) . '"' : '';
                return '<?php echo $this->yieldContent("' . $name . '"' . $default . '); ?>';
            },
            $code
        );

        return $code;
    }
}