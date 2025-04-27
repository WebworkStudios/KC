<?php


namespace Src\Http\Middleware;

use Src\Database\Anonymization\AnonymizationService;
use Src\Http\Middleware;
use Src\Http\Request;
use Src\Http\Response;
use Src\Log\LoggerInterface;

/**
 * Middleware zur automatischen Anonymisierung von API-Antworten
 *
 * Diese Middleware kann verwendet werden, um sensible Daten in API-Antworten
 * automatisch zu anonymisieren, basierend auf Konfiguration oder Anfrageparametern.
 */
class AnonymizationMiddleware implements Middleware
{
    /**
     * Felder, die standardmäßig anonymisiert werden sollen
     *
     * @var array
     */
    private array $sensitiveFields;

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
     * Liste von Pfaden, für die die Anonymisierung deaktiviert werden soll
     *
     * @var array
     */
    private array $excludedPaths;

    /**
     * Erstellt eine neue AnonymizationMiddleware
     *
     * @param AnonymizationService $anonymizationService AnonymizationService-Instanz
     * @param LoggerInterface $logger Logger für Anonymisierungsoperationen
     * @param array $sensitiveFields Felder, die standardmäßig anonymisiert werden sollen
     * @param array $excludedPaths Pfade, für die die Anonymisierung deaktiviert werden soll
     */
    public function __construct(
        AnonymizationService $anonymizationService,
        LoggerInterface      $logger,
        array                $sensitiveFields = [],
        array                $excludedPaths = []
    )
    {
        $this->anonymizationService = $anonymizationService;
        $this->logger = $logger;
        $this->sensitiveFields = $sensitiveFields;
        $this->excludedPaths = $excludedPaths;
    }

    /**
     * {@inheritDoc}
     */
    public function process(Request $request, callable $next): ?Response
    {
        // Anfrage weiterleiten
        $response = $next($request);

        // Wenn keine Response zurückgegeben wurde, nichts tun
        if (!$response instanceof Response) {
            return $response;
        }

        // Prüfen ob der Pfad ausgeschlossen ist
        $path = $request->getPath();
        foreach ($this->excludedPaths as $excludedPath) {
            if (fnmatch($excludedPath, $path)) {
                $this->logger->debug("Anonymisierung für Pfad deaktiviert", [
                    'path' => $path,
                    'matched_exclude' => $excludedPath
                ]);
                return $response;
            }
        }

        // Anonymisierungsparameter aus der Anfrage extrahieren
        $anonymizeQuery = $request->query('anonymize');
        $anonymizeHeader = $request->getHeader('X-Anonymize');

        // Prüfen, ob Anonymisierung aktiviert ist
        $shouldAnonymize = $anonymizeQuery === 'true' || $anonymizeQuery === '1' ||
            $anonymizeHeader === 'true' || $anonymizeHeader === '1';

        // Wenn nicht explizit aktiviert, nichts tun
        if (!$shouldAnonymize) {
            return $response;
        }

        $this->logger->info("Anonymisiere API-Antwort", [
            'path' => $path,
            'content_type' => $response->getHeaders()['Content-Type'] ?? 'unknown'
        ]);

        // Content-Type prüfen - nur JSON anonymisieren
        $contentType = $response->getHeaders()['Content-Type'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            $this->logger->debug("Überspringt Anonymisierung für nicht-JSON-Antwort", [
                'content_type' => $contentType
            ]);
            return $response;
        }

        // JSON-Inhalt extrahieren und anonymisieren
        $content = $response->getContent();
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->logger->warning("Konnte JSON nicht dekodieren", [
                'error' => json_last_error_msg()
            ]);
            return $response;
        }

        // Anonymisierung anwenden
        $anonymizedData = $this->anonymizeResponseData($data);

        // Neue Response mit anonymisierten Daten erstellen
        $anonymizedContent = json_encode($anonymizedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newResponse = new Response(
            $anonymizedContent,
            $response->getStatus(),
            'application/json; charset=UTF-8'
        );

        // Alle Header übernehmen
        foreach ($response->getHeaders() as $name => $value) {
            if ($name !== 'Content-Type') {
                $newResponse->setHeader($name, $value);
            }
        }

        // Header hinzufügen um zu signalisieren, dass die Antwort anonymisiert wurde
        $newResponse->setHeader('X-Anonymized', 'true');

        return $newResponse;
    }

    /**
     * Anonymisiert die Daten in einer API-Antwort
     *
     * @param array $data Zu anonymisierende Daten
     * @return array Anonymisierte Daten
     */
    private function anonymizeResponseData(array $data): array
    {
        // Prüfen, ob es sich um ein einzelnes Objekt oder eine Liste handelt
        $isList = isset($data[0]) && is_array($data[0]);

        if ($isList) {
            // Liste von Objekten
            return array_map(fn($item) => $this->anonymizeObject($item), $data);
        } else if (isset($data['data']) && is_array($data['data'])) {
            // API-Response mit data-Schlüssel, der eine Liste oder ein Objekt enthält
            if (isset($data['data'][0]) && is_array($data['data'][0])) {
                // data enthält eine Liste
                $data['data'] = array_map(fn($item) => $this->anonymizeObject($item), $data['data']);
            } else {
                // data enthält ein einzelnes Objekt
                $data['data'] = $this->anonymizeObject($data['data']);
            }
            return $data;
        } else {
            // Einzelnes Objekt
            return $this->anonymizeObject($data);
        }
    }

    /**
     * Anonymisiert ein einzelnes Objekt
     *
     * @param array $object Zu anonymisierendes Objekt
     * @return array Anonymisiertes Objekt
     */
    private function anonymizeObject(array $object): array
    {
        return $this->anonymizationService->anonymizeData($object, $this->sensitiveFields);
    }

    /**
     * Setzt die zu anonymisierenden Felder
     *
     * @param array $fields Felder mit Anonymisierungsstrategien
     * @return self
     */
    public function setSensitiveFields(array $fields): self
    {
        $this->sensitiveFields = $fields;
        return $this;
    }

    /**
     * Fügt weitere zu anonymisierende Felder hinzu
     *
     * @param array $fields Felder mit Anonymisierungsstrategien
     * @return self
     */
    public function addSensitiveFields(array $fields): self
    {
        $this->sensitiveFields = array_merge($this->sensitiveFields, $fields);
        return $this;
    }

    /**
     * Setzt die auszuschließenden Pfade
     *
     * @param array $paths Pfade, für die die Anonymisierung deaktiviert werden soll
     * @return self
     */
    public function setExcludedPaths(array $paths): self
    {
        $this->excludedPaths = $paths;
        return $this;
    }
}