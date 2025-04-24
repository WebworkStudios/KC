<?php
declare(strict_types=1);

namespace App\Core\Database;

/**
 * Klasse für rohe SQL-Ausdrücke
 */
class Expression
{
    /**
     * SQL-Ausdruck
     */
    private string $value;

    /**
     * Konstruktor
     *
     * @param string $value SQL-Ausdruck
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Gibt den SQL-Ausdruck zurück
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * String-Repräsentation des Ausdrucks
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}