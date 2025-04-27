<?php

namespace Src\Database\Anonymization;

use Attribute;

/**
 * Attribut für Modellfelder, die anonymisiert werden sollen
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Anonymizable
{
    /**
     * Erstellt ein neues Anonymizable-Attribut
     *
     * @param string $strategy Name der Anonymisierungsstrategie
     * @param array $options Optionen für die Anonymisierung
     * @param bool $alwaysAnonymize True, wenn das Feld immer anonymisiert werden soll
     */
    public function __construct(
        public readonly string $strategy = 'name',
        public readonly array $options = [],
        public readonly bool $alwaysAnonymize = false
    ) {
    }
}