<?php

namespace Src\Container;

/**
 * Interface für Objekte, die Hooks für Property-Änderungen implementieren möchten
 */
interface PropertyHookAware
{
    /**
     * Wird aufgerufen, wenn sich eine Eigenschaft ändert
     *
     * @param string $property Name der geänderten Eigenschaft
     * @param mixed $oldValue Alter Wert
     * @param mixed $newValue Neuer Wert
     * @return void
     */
    public function onPropertyChanged(string $property, mixed $oldValue, mixed $newValue): void;
}