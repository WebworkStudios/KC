<?php

namespace Src\Container;

use Attribute;

/**
 * Attribut zum Markieren von Properties für Auto-Wiring
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Inject
{
    /**
     * Erstellt eine neue Inject-Annotation
     */
    public function __construct(
        public readonly ?string $serviceId = null
    )
    {
    }
}