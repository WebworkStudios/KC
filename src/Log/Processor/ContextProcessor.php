<?php

namespace Src\Log\Processor;

/**
 * Prozessor zum Anreichern von Log-Kontext mit zusätzlichen Informationen
 */
class ContextProcessor
{
    /** @var array Zusätzliche Kontext-Informationen */
    private array $extraContext = [];

    /**
     * Erstellt einen neuen ContextProcessor
     *
     * @param array $extraContext Zusätzliche Kontextinformationen
     */
    public function __construct(array $extraContext = [])
    {
        $this->extraContext = $extraContext;
    }

    /**
     * Fügt zusätzliche Kontext-Informationen hinzu
     *
     * @param string $key Schlüssel
     * @param mixed $value Wert
     * @return self
     */
    public function addContext(string $key, mixed $value): self
    {
        $this->extraContext[$key] = $value;
        return $this;
    }

    /**
     * Fügt mehrere Kontext-Informationen hinzu
     *
     * @param array $context Kontextinformationen
     * @return self
     */
    public function addContexts(array $context): self
    {
        $this->extraContext = array_merge($this->extraContext, $context);
        return $this;
    }

    /**
     * Verarbeitet den Log-Kontext
     *
     * @param string $level Log-Level
     * @param string $message Log-Nachricht
     * @param array $context Ursprünglicher Kontext
     * @return array Erweiterter Kontext
     */
    public function __invoke(string $level, string $message, array $context): array
    {
        // Standard-Informationen für alle Logs hinzufügen
        $defaultContext = [
            'timestamp' => time(),
            'memory_usage' => $this->formatBytes(memory_get_usage()),
            'process_id' => getmypid(),
        ];

        // HTTP-Request-Informationen, falls verfügbar
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $defaultContext['http_method'] = $_SERVER['REQUEST_METHOD'];
            $defaultContext['request_uri'] = $_SERVER['REQUEST_URI'] ?? '';
            $defaultContext['remote_addr'] = $_SERVER['REMOTE_ADDR'] ?? '';
        }

        // Zusammenführen der Kontextinformationen mit Priorität für Benutzerkontext
        return array_merge($defaultContext, $this->extraContext, $context);
    }

    /**
     * Formatiert Bytes in lesbare Größe
     *
     * @param int $bytes Bytezahl
     * @param int $precision Nachkommastellen
     * @return string Formatierte Größe
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}