<?php


declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Compiler-Pass für Kommentare
 *
 * Entfernt Kommentare aus dem Template
 */
class CommentPass extends AbstractCompilerPass
{
    /**
     * {@inheritDoc}
     */
    protected int $priority = 10;

    /**
     * {@inheritDoc}
     */
    protected string $name = 'Comments';

    /**
     * {@inheritDoc}
     */
    public function process(string $code, array $context = []): string
    {
        // Entferne {# Kommentar #}
        return preg_replace('/{#.*?#}/s', '', $code);
    }
}