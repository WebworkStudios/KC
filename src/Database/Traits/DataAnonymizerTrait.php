<?php

namespace Src\Database\Traits;

use ReflectionClass;
use ReflectionProperty;
use Src\Database\Anonymization\Anonymizable;
use Src\Database\Anonymization\AnonymizationService;

/**
 * Trait für Modellklassen zur Unterstützung der Datenanonymisierung
 */
trait DataAnonymizerTrait
{
    /**
     * Cache für anonymisierbare Felder
     *
     * @var array|null
     */
    private static ?array $anonymizableFields = null;

    /**
     * Gibt eine anonymisierte Version des Objekts zurück
     *
     * @param AnonymizationService|null $anonymizer Optional: AnonymizationService-Instanz
     * @param array $additionalFields Zusätzliche zu anonymisierende Felder
     * @param array $excludeFields Felder, die von der Anonymisierung ausgeschlossen werden sollen
     * @return array Anonymisierte Daten als Array
     */
    public function toAnonymizedArray(
        ?AnonymizationService $anonymizer = null,
        array                 $additionalFields = [],
        array                 $excludeFields = []
    ): array
    {
        // Wenn kein Anonymisierer übergeben wurde, einen neuen erstellen
        $anonymizer = $anonymizer ?? new AnonymizationService();

        // Objekt in Array umwandeln
        $data = $this->toArray();

        // Anonymisierbare Felder ermitteln
        $anonymizableFields = $this->getAnonymizableFields();

        // Zusätzliche Felder hinzufügen
        foreach ($additionalFields as $field => $config) {
            if (is_int($field) && is_string($config)) {
                // Format: ['field1', 'field2'] -> Standardstrategie verwenden
                $anonymizableFields[$config] = ['strategy' => 'name'];
            } else {
                // Format: ['field1' => 'strategy'] oder ['field1' => ['strategy' => 'x', 'options' => [...]]]
                $anonymizableFields[$field] = is_string($config) ? ['strategy' => $config] : $config;
            }
        }

        // Ausgeschlossene Felder entfernen
        foreach ($excludeFields as $field) {
            unset($anonymizableFields[$field]);
        }

        // Felder anonymisieren
        foreach ($anonymizableFields as $field => $config) {
            if (!isset($data[$field])) {
                continue;
            }

            $strategy = is_array($config) ? $config['strategy'] : $config;
            $options = is_array($config) && isset($config['options']) ? $config['options'] : [];

            $data[$field] = $anonymizer->anonymize($data[$field], $strategy, $options);
        }

        return $data;
    }

    /**
     * Konvertiert das Objekt in ein Array
     *
     * Diese Methode muss von der implementierenden Klasse bereitgestellt werden,
     * falls sie nicht bereits existiert.
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = [];
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            $data[$name] = $property->getValue($this);
        }

        return $data;
    }

    /**
     * Ermittelt die anonymisierbaren Felder der Klasse
     *
     * @return array Anonymisierbare Felder mit ihren Konfigurationen
     */
    private function getAnonymizableFields(): array
    {
        // Klassennamen ermitteln
        $class = get_class($this);

        // Wenn bereits im Cache, zurückgeben
        if (isset(self::$anonymizableFields[$class])) {
            return self::$anonymizableFields[$class];
        }

        // Reflektion verwenden, um Attribute zu finden
        $reflection = new ReflectionClass($class);
        $fields = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Anonymizable::class);

            if (empty($attributes)) {
                continue;
            }

            // Erstes Attribut verwenden
            $attribute = $attributes[0]->newInstance();
            $propertyName = $property->getName();

            $fields[$propertyName] = [
                'strategy' => $attribute->strategy,
                'options' => $attribute->options,
                'always_anonymize' => $attribute->alwaysAnonymize
            ];
        }

        // Im Cache speichern
        self::$anonymizableFields[$class] = $fields;

        return $fields;
    }
}