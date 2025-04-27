<?php


namespace Src\Services;

use RuntimeException;
use Src\Database\Anonymization\AnonymizationService;
use Src\Log\LoggerInterface;
use Src\Log\NullLogger;
use Throwable;

/**
 * Service für DSGVO-konforme Datenexporte mit automatischer Anonymisierung
 *
 * Dieser Service ermöglicht es, Daten für DSGVO-Anfragen zu exportieren,
 * wobei bestimmte sensible Daten automatisch anonymisiert werden können.
 */
class GdprExportService
{
    /**
     * AnonymizationService-Instanz
     *
     * @var AnonymizationService
     */
    private AnonymizationService $anonymizationService;

    /**
     * Logger-Instanz
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Felder, die in DSGVO-Exporten anonymisiert werden sollen
     *
     * @var array
     */
    private array $fieldsToAnonymize;

    /**
     * Erstellt einen neuen GdprExportService
     *
     * @param AnonymizationService|null $anonymizationService AnonymizationService-Instanz
     * @param LoggerInterface|null $logger Logger für Exportoperationen
     * @param array $fieldsToAnonymize Felder, die in DSGVO-Exporten anonymisiert werden sollen
     */
    public function __construct(
        ?AnonymizationService $anonymizationService = null,
        ?LoggerInterface      $logger = null,
        array                 $fieldsToAnonymize = []
    )
    {
        $this->anonymizationService = $anonymizationService ?? new AnonymizationService();
        $this->logger = $logger ?? new NullLogger();

        // Standard-Felder für die Anonymisierung festlegen
        $this->fieldsToAnonymize = $fieldsToAnonymize;

        if (empty($this->fieldsToAnonymize)) {
            $this->fieldsToAnonymize = $this->getDefaultAnonymizationFields();
        }
    }

    /**
     * Gibt die Standardfelder für die Anonymisierung zurück
     *
     * @return array Standardfelder für die Anonymisierung
     */
    private function getDefaultAnonymizationFields(): array
    {
        return [
            'password' => 'null',
            'password_hash' => 'null',
            'security_question' => 'null',
            'security_answer' => 'null',
            'credit_card_number' => 'credit_card',
            'card_number' => 'credit_card',
            'cvv' => 'null',
            'social_security_number' => [
                'strategy' => 'hash',
                'options' => ['salt' => 'gdpr-export']
            ],
            'ip_address' => 'ip',
            'last_ip' => 'ip',
            'browser_fingerprint' => 'null',
            'device_id' => 'hash',
            'session_token' => 'null',
            'auth_token' => 'null',
            'access_token' => 'null',
            'refresh_token' => 'null',
            'remember_token' => 'null',
        ];
    }

    /**
     * Erstellt einen DSGVO-konformen Datenexport für einen Benutzer
     *
     * @param int $userId ID des Benutzers
     * @param array $dataSources Array von Callbacks, die Benutzerdaten zurückgeben
     * @param bool $anonymizeSensitiveData True, wenn sensible Daten anonymisiert werden sollen
     * @return array Exportierte Daten
     */
    public function createUserDataExport(
        int   $userId,
        array $dataSources,
        bool  $anonymizeSensitiveData = true
    ): array
    {
        $this->logger->info("Erstelle DSGVO-Datenexport", [
            'user_id' => $userId,
            'data_sources' => count($dataSources),
            'anonymize' => $anonymizeSensitiveData
        ]);

        $exportData = [
            'export_timestamp' => date('c'),
            'user_id' => $userId,
            'data' => []
        ];

        // Datenquellen abfragen
        foreach ($dataSources as $key => $callback) {
            // Sicherstellen, dass der Schlüssel ein String ist
            $sourceName = is_int($key) ? "source_$key" : $key;

            try {
                $data = call_user_func($callback, $userId);

                // Daten anonymisieren, falls gewünscht
                if ($anonymizeSensitiveData && is_array($data)) {
                    if (isset($data[0]) && is_array($data[0])) {
                        // Liste von Objekten
                        $data = $this->anonymizationService->anonymizeDataSet($data, $this->fieldsToAnonymize);
                    } else {
                        // Einzelnes Objekt
                        $data = $this->anonymizationService->anonymizeData($data, $this->fieldsToAnonymize);
                    }
                }

                $exportData['data'][$sourceName] = $data;

                $this->logger->debug("Datenquelle erfolgreich exportiert", [
                    'source' => $sourceName,
                    'records' => is_array($data) ? (isset($data[0]) ? count($data) : 1) : 'nicht zählbar'
                ]);
            } catch (Throwable $e) {
                $this->logger->error("Fehler beim Exportieren einer Datenquelle", [
                    'source' => $sourceName,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e)
                ]);

                $exportData['data'][$sourceName] = [
                    'error' => 'Fehler beim Exportieren dieser Datenquelle',
                    'error_details' => $e->getMessage()
                ];
            }
        }

        $this->logger->info("DSGVO-Datenexport abgeschlossen", [
            'user_id' => $userId,
            'export_timestamp' => $exportData['export_timestamp'],
            'total_sources' => count($exportData['data'])
        ]);

        return $exportData;
    }

    /**
     * Speichert einen Datenexport als JSON-Datei
     *
     * @param array $exportData Die zu exportierenden Daten
     * @param string $directory Verzeichnis, in dem die Datei gespeichert werden soll
     * @param string|null $filename Optionaler Dateiname (ohne Erweiterung)
     * @return string Pfad zur gespeicherten Datei
     */
    public function saveExportToJson(array $exportData, string $directory, ?string $filename = null): string
    {
        // Sicherstellen, dass das Verzeichnis existiert
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Das Verzeichnis '$directory' konnte nicht erstellt werden");
        }

        // Dateinamen generieren, wenn nicht angegeben
        if ($filename === null) {
            $userId = $exportData['user_id'] ?? 'unknown';
            $timestamp = date('Ymd_His');
            $filename = "user_{$userId}_export_{$timestamp}";
        }

        // Pfad zur Datei erstellen
        $filePath = rtrim($directory, '/') . '/' . $filename . '.json';

        // Daten als JSON speichern
        $json = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException("Fehler beim Kodieren der Daten als JSON: " . json_last_error_msg());
        }

        $bytesWritten = file_put_contents($filePath, $json);

        if ($bytesWritten === false) {
            throw new RuntimeException("Fehler beim Schreiben der Datei: $filePath");
        }

        $this->logger->info("DSGVO-Datenexport als JSON gespeichert", [
            'file' => $filePath,
            'size' => $bytesWritten
        ]);

        return $filePath;
    }

    /**
     * Setzt die Felder, die in DSGVO-Exporten anonymisiert werden sollen
     *
     * @param array $fields Felder mit Anonymisierungsstrategien
     * @return self
     */
    public function setFieldsToAnonymize(array $fields): self
    {
        $this->fieldsToAnonymize = $fields;
        return $this;
    }

    /**
     * Fügt weitere zu anonymisierende Felder hinzu
     *
     * @param array $fields Felder mit Anonymisierungsstrategien
     * @return self
     */
    public function addFieldsToAnonymize(array $fields): self
    {
        $this->fieldsToAnonymize = array_merge($this->fieldsToAnonymize, $fields);
        return $this;
    }

    /**
     * Gibt die AnonymizationService-Instanz zurück
     *
     * @return AnonymizationService
     */
    public function getAnonymizationService(): AnonymizationService
    {
        return $this->anonymizationService;
    }
}