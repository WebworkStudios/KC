<?php


namespace Src\Database\Anonymization;

use InvalidArgumentException;
use Src\Log\LoggerInterface;
use Src\Log\NullLogger;

/**
 * Service für die Anonymisierung sensibler Daten
 *
 * Bietet verschiedene Methoden zum Anonymisieren von Daten unterschiedlicher Typen
 * mit konfigurierbaren Optionen für verschiedene Anwendungsfälle.
 */
class AnonymizationService
{
    /** @var array<string, callable> Registrierte Anonymisierungsstrategien */
    private array $strategies = [];

    /** @var LoggerInterface Logger für Anonymisierungsoperationen */
    private LoggerInterface $logger;

    /**
     * Erstellt eine neue AnonymizationService-Instanz
     *
     * @param LoggerInterface|null $logger Optional: Logger für Anonymisierungsoperationen
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();

        // Standard-Anonymisierungsstrategien registrieren
        $this->registerDefaultStrategies();
    }

    /**
     * Registriert die Standard-Anonymisierungsstrategien
     *
     * @return void
     */
    private function registerDefaultStrategies(): void
    {
        // E-Mail-Anonymisierung
        $this->registerStrategy('email', function (string $value, array $options = []): string {
            $preserveDomain = $options['preserve_domain'] ?? true;

            if (empty($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }

            [$local, $domain] = explode('@', $value);

            // Lokalen Teil anonymisieren
            $length = strlen($local);
            $visibleChars = min(3, $length);

            if ($length <= $visibleChars) {
                $anonymized = str_repeat('*', $length);
            } else {
                $anonymized = substr($local, 0, $visibleChars) . str_repeat('*', $length - $visibleChars);
            }

            // Domain beibehalten oder anonymisieren
            if ($preserveDomain) {
                return $anonymized . '@' . $domain;
            }

            return $anonymized . '@example.com';
        });

        // Name-Anonymisierung
        $this->registerStrategy('name', function (string $value, array $options = []): string {
            $preserveFirstChar = $options['preserve_first_char'] ?? true;
            $placeholder = $options['placeholder'] ?? '****';

            if (empty($value)) {
                return $value;
            }

            if ($preserveFirstChar && strlen($value) > 1) {
                return $value[0] . $placeholder;
            }

            return $placeholder;
        });

        // Telefonnummer-Anonymisierung
        $this->registerStrategy('phone', function (string $value, array $options = []): string {
            $visibleDigits = $options['visible_digits'] ?? 3;

            if (empty($value)) {
                return $value;
            }

            // Nur Ziffern behalten
            $digits = preg_replace('/\D/', '', $value);
            $length = strlen($digits);

            if ($length <= $visibleDigits) {
                return str_repeat('*', $length);
            }

            $anonymized = str_repeat('*', $length - $visibleDigits) .
                substr($digits, -$visibleDigits);

            // Format beibehalten
            $result = '';
            $digitIndex = 0;

            for ($i = 0; $i < strlen($value); $i++) {
                if (ctype_digit($value[$i])) {
                    $result .= $anonymized[$digitIndex++] ?? '*';
                } else {
                    $result .= $value[$i];
                }
            }

            return $result;
        });

        // Adress-Anonymisierung
        $this->registerStrategy('address', function (string $value, array $options = []): string {
            $preservePostalCode = $options['preserve_postal_code'] ?? true;

            if (empty($value)) {
                return $value;
            }

            // Postleitzahl finden und bewahren
            if ($preservePostalCode) {
                $postalCodePattern = '/\b\d{4,5}\b/';
                if (preg_match($postalCodePattern, $value, $matches)) {
                    $postalCode = $matches[0];
                    return "[Anonymisierte Adresse, PLZ: $postalCode]";
                }
            }

            return "[Anonymisierte Adresse]";
        });

        // IP-Adress-Anonymisierung
        $this->registerStrategy('ip', function (string $value, array $options = []): string {
            $method = $options['method'] ?? 'partial';

            if (empty($value)) {
                return $value;
            }

            if (!filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }

            // IPv4 oder IPv6 erkennen
            $isIPv6 = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

            if ($isIPv6) {
                // IPv6 anonymisieren
                $segments = explode(':', $value);
                $preservedCount = $method === 'partial' ? 4 : 0;

                for ($i = $preservedCount; $i < count($segments); $i++) {
                    $segments[$i] = '0000';
                }

                return implode(':', $segments);
            } else {
                // IPv4 anonymisieren
                $segments = explode('.', $value);
                $preservedCount = $method === 'partial' ? 2 : 0;

                for ($i = $preservedCount; $i < count($segments); $i++) {
                    $segments[$i] = '0';
                }

                return implode('.', $segments);
            }
        });

        // Kreditkarten-Anonymisierung
        $this->registerStrategy('credit_card', function (string $value, array $options = []): string {
            $visibleDigits = $options['visible_digits'] ?? 4;

            if (empty($value)) {
                return $value;
            }

            // Nur Ziffern behalten
            $digits = preg_replace('/\D/', '', $value);
            $length = strlen($digits);

            if ($length <= 4) {
                return str_repeat('*', $length);
            }

            $anonymized = str_repeat('*', $length - $visibleDigits) .
                substr($digits, -$visibleDigits);

            // Format beibehalten (Leerzeichen, Bindestriche)
            $result = '';
            $digitIndex = 0;

            for ($i = 0; $i < strlen($value); $i++) {
                if (ctype_digit($value[$i])) {
                    $result .= $anonymized[$digitIndex++] ?? '*';
                } else {
                    $result .= $value[$i];
                }
            }

            return $result;
        });

        // Hash-basierte Anonymisierung (deterministisch)
        $this->registerStrategy('hash', function (string $value, array $options = []): string {
            $algorithm = $options['algorithm'] ?? 'xxh3';
            $salt = $options['salt'] ?? '';
            $length = $options['length'] ?? 0;

            if (empty($value)) {
                return $value;
            }

            $hashed = hash($algorithm, $salt . $value);

            if ($length > 0 && $length < strlen($hashed)) {
                $hashed = substr($hashed, 0, $length);
            }

            return $hashed;
        });

        // Zufalls-Token (nicht-deterministisch)
        $this->registerStrategy('random', function (string $value, array $options = []): string {
            $length = $options['length'] ?? 10;
            $characters = $options['characters'] ?? 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

            if (empty($value)) {
                return $value;
            }

            $result = '';
            $max = strlen($characters) - 1;

            for ($i = 0; $i < $length; $i++) {
                $result .= $characters[random_int(0, $max)];
            }

            return $result;
        });

        // Nullwert-Strategie (Wert durch NULL ersetzen)
        $this->registerStrategy('null', function (string $value, array $options = []): ?string {
            return null;
        });
    }

    /**
     * Registriert eine Anonymisierungsstrategie
     *
     * @param string $name Name der Strategie
     * @param callable $strategy Callback-Funktion für die Anonymisierung
     * @return self
     */
    public function registerStrategy(string $name, callable $strategy): self
    {
        $this->strategies[$name] = $strategy;

        $this->logger->debug("Anonymisierungsstrategie registriert", [
            'strategy' => $name
        ]);

        return $this;
    }

    /**
     * Anonymisiert mehrere Datensätze
     *
     * @param array<array> $dataSet Array von Datensätzen
     * @param array $fields Zu anonymisierende Felder mit Strategien
     * @return array<array> Anonymisierte Datensätze
     */
    public function anonymizeDataSet(array $dataSet, array $fields): array
    {
        $result = [];

        foreach ($dataSet as $data) {
            $result[] = $this->anonymizeData($data, $fields);
        }

        return $result;
    }

    /**
     * Anonymisiert mehrere Werte in einem Datensatz
     *
     * @param array $data Zu anonymisierender Datensatz
     * @param array $fields Zu anonymisierende Felder mit Strategien
     * @return array Anonymisierter Datensatz
     */
    public function anonymizeData(array $data, array $fields): array
    {
        $result = $data;

        foreach ($fields as $field => $config) {
            if (!isset($data[$field])) {
                continue;
            }

            $strategy = is_string($config) ? $config : ($config['strategy'] ?? 'name');
            $options = is_array($config) && isset($config['options']) ? $config['options'] : [];

            $result[$field] = $this->anonymize($data[$field], $strategy, $options);
        }

        return $result;
    }

    /**
     * Führt die Anonymisierung eines Wertes durch
     *
     * @param mixed $value Zu anonymisierender Wert
     * @param string $strategy Name der zu verwendenden Strategie
     * @param array $options Optionen für die Anonymisierung
     * @return mixed Anonymisierter Wert
     * @throws InvalidArgumentException Wenn die Strategie nicht existiert
     */
    public function anonymize(mixed $value, string $strategy, array $options = []): mixed
    {
        // Nur Strings anonymisieren
        if (!is_string($value) || empty($value)) {
            return $value;
        }

        if (!isset($this->strategies[$strategy])) {
            throw new InvalidArgumentException("Anonymisierungsstrategie '$strategy' nicht gefunden");
        }

        $originalLength = strlen($value);
        $result = ($this->strategies[$strategy])($value, $options);

        $this->logger->debug("Wert anonymisiert", [
            'strategy' => $strategy,
            'original_length' => $originalLength,
            'anonymized_length' => is_string($result) ? strlen($result) : 0
        ]);

        return $result;
    }
}