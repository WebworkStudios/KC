<?php

namespace Src\Container;

/**
 * Attribut zum Markieren von Properties, die überwacht werden sollen
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Observable
{
    /**
     * Erstellt eine neue Observable-Annotation
     */
    public function __construct(
        public readonly ?string $callback = null
    )
    {
    }
}