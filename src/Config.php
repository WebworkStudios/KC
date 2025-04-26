<?php

namespace Src;

/**
 * Konfigurationsklasse für die Anwendung
 *
 * Enthält alle Konfigurationsoptionen für verschiedene Komponenten
 */
class Config
{
    /** @var array Konfigurationswerte */
    private array $config = [];

    /**
     * Erstellt eine neue Konfiguration
     *
     * @param array $config Konfigurationswerte
     */
    public function __construct(array $config = [])
    {
        // Standardkonfiguration
        $defaultConfig = [
            'app' => [
                'name' => 'PHP 8.4 ADR Framework',
                'environment' => 'development',
                'debug' => true,
                'url' => 'http://localhost:8000',
            ],
            'logging' => [
                'dir' => dirname(__DIR__) . '/logs',
                'level' => 'debug',
                'types' => [
                    'development' => ['file'],
                    'production' => ['file', 'syslog'],
                ],
                'file' => [
                    'filename' => 'app.log',
                    'mode' => 'a',
                ],
                'syslog' => [
                    'ident' => 'php-adr',
                    'facility' => LOG_USER,
                ],
            ],
            'router' => [
                'action_dir' => dirname(__DIR__) . '/app/Actions',
                'action_namespace' => 'App\\Actions',
            ],
        ];

        // Benutzerkonfiguration mit Standardkonfiguration zusammenführen
        $this->config = array_replace_recursive($defaultConfig, $config);
    }

    /**
     * Gibt einen Konfigurationswert zurück
     *
     * @param string $key Konfigurationsschlüssel (mit Punktnotation, z.B. 'app.name')
     * @param mixed $default Standardwert, falls Schlüssel nicht existiert
     * @return mixed Konfigurationswert oder Standardwert
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Punkt-Notation in Array-Pfad umwandeln
        $parts = explode('.', $key);
        $config = $this->config;

        foreach ($parts as $part) {
            if (!is_array($config) || !array_key_exists($part, $config)) {
                return $default;
            }
            $config = $config[$part];
        }

        return $config;
    }

    /**
     * Prüft, ob ein Konfigurationsschlüssel existiert
     *
     * @param string $key Konfigurationsschlüssel (mit Punktnotation)
     * @return bool True, wenn der Schlüssel existiert
     */
    public function has(string $key): bool
    {
        $parts = explode('.', $key);
        $config = $this->config;

        foreach ($parts as $part) {
            if (!is_array($config) || !array_key_exists($part, $config)) {
                return false;
            }
            $config = $config[$part];
        }

        return true;
    }

    /**
     * Setzt einen Konfigurationswert
     *
     * @param string $key Konfigurationsschlüssel (mit Punktnotation)
     * @param mixed $value Neuer Wert
     * @return self
     */
    public function set(string $key, mixed $value): self
    {
        $parts = explode('.', $key);
        $configRef = &$this->config;

        foreach ($parts as $i => $part) {
            if (!is_array($configRef)) {
                $configRef = [];
            }

            // Letzter Teil: Wert setzen
            if ($i === count($parts) - 1) {
                $configRef[$part] = $value;
            } else {
                // Zwischenteil: Referenz aktualisieren
                if (!isset($configRef[$part]) || !is_array($configRef[$part])) {
                    $configRef[$part] = [];
                }
                $configRef = &$configRef[$part];
            }
        }

        return $this;
    }

    /**
     * Gibt alle Konfigurationswerte zurück
     *
     * @return array Alle Konfigurationswerte
     */
    public function all(): array
    {
        return $this->config;
    }
}