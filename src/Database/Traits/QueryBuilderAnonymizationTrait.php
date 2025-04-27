<?php

namespace Src\Database\Traits;

use Src\Database\Anonymization\AnonymizationService;

/**
 * Trait für den QueryBuilder zur Unterstützung der Datenanonymisierung
 */
trait QueryBuilderAnonymizationTrait
{
    /** @var AnonymizationService|null AnonymizationService-Instanz */
    private ?AnonymizationService $anonymizationService = null;

    /** @var array|null Felder, die anonymisiert werden sollen */
    private ?array $anonymizeFields = null;

    /** @var bool Gibt an, ob Anonymisierung aktiviert ist */
    private bool $anonymizationEnabled = false;

    /**
     * Aktiviert die Anonymisierung für bestimmte Felder
     *
     * @param array $fields Felder, die anonymisiert werden sollen
     * @param AnonymizationService|null $service Optional: AnonymizationService-Instanz
     * @return self
     */
    public function anonymize(array $fields, ?AnonymizationService $service = null): self
    {
        $this->anonymizationEnabled = true;
        $this->anonymizeFields = $fields;
        $this->anonymizationService = $service ?? $this->getAnonymizationService();

        $this->logger->debug("Anonymisierung aktiviert", [
            'connection' => $this->connectionName,
            'table' => $this->table,
            'fields' => array_keys($fields)
        ]);

        return $this;
    }

    /**
     * Gibt die AnonymizationService-Instanz zurück
     *
     * @return AnonymizationService
     */
    private function getAnonymizationService(): AnonymizationService
    {
        if ($this->anonymizationService === null) {
            $this->anonymizationService = new AnonymizationService($this->logger);
        }

        return $this->anonymizationService;
    }

    /**
     * Deaktiviert die Anonymisierung
     *
     * @return self
     */
    public function withoutAnonymization(): self
    {
        $this->anonymizationEnabled = false;

        $this->logger->debug("Anonymisierung deaktiviert", [
            'connection' => $this->connectionName,
            'table' => $this->table
        ]);

        return $this;
    }

    /**
     * Überschreibt die get()-Methode, um Anonymisierung anzuwenden
     *
     * HINWEIS: Diese Methode muss im QueryBuilder aufgerufen werden, nachdem
     * das ursprüngliche Ergebnis abgerufen wurde.
     *
     * @param array $results Die ursprünglichen Ergebnisse
     * @return array Die anonymisierten Ergebnisse
     */
    private function anonymizeResults(array $results): array
    {
        if (!$this->anonymizationEnabled || empty($this->anonymizeFields) || empty($results)) {
            return $results;
        }

        $this->logger->debug("Anonymisiere Abfrageergebnisse", [
            'connection' => $this->connectionName,
            'table' => $this->table,
            'fields' => array_keys($this->anonymizeFields),
            'result_count' => count($results)
        ]);

        return $this->applyAnonymizationToDataSet($results);
    }

    /**
     * Führt die Anonymisierung auf einem Ergebnisset durch
     *
     * @param array $dataSet Zu anonymisierendes Datenset
     * @return array Anonymisiertes Datenset
     */
    private function applyAnonymizationToDataSet(array $dataSet): array
    {
        if (!$this->anonymizationEnabled || empty($this->anonymizeFields) || empty($dataSet)) {
            return $dataSet;
        }

        return $this->getAnonymizationService()->anonymizeDataSet($dataSet, $this->anonymizeFields);
    }

    /**
     * Überschreibt die first()-Methode, um Anonymisierung anzuwenden
     *
     * HINWEIS: Diese Methode muss im QueryBuilder aufgerufen werden, nachdem
     * das ursprüngliche Ergebnis abgerufen wurde.
     *
     * @param array|null $result Das ursprüngliche Ergebnis
     * @return array|null Das anonymisierte Ergebnis
     */
    private function anonymizeFirstResult(?array $result): ?array
    {
        if (!$this->anonymizationEnabled || empty($this->anonymizeFields) || $result === null) {
            return $result;
        }

        $this->logger->debug("Anonymisiere einzelnes Abfrageergebnis", [
            'connection' => $this->connectionName,
            'table' => $this->table,
            'fields' => array_keys($this->anonymizeFields)
        ]);

        return $this->applyAnonymization($result);
    }

    /**
     * Führt die Anonymisierung auf einem Ergebnisdatensatz durch
     *
     * @param array $data Zu anonymisierender Datensatz
     * @return array Anonymisierter Datensatz
     */
    private function applyAnonymization(array $data): array
    {
        if (!$this->anonymizationEnabled || empty($this->anonymizeFields) || empty($data)) {
            return $data;
        }

        return $this->getAnonymizationService()->anonymizeData($data, $this->anonymizeFields);
    }
}