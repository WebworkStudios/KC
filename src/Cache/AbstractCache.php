<?php

namespace Src\Cache;

use InvalidArgumentException;
use Src\Log\LoggerInterface;
use Src\Log\NullLogger;

/**
 * Abstrakte Basisklasse für Cache-Implementierungen
 *
 * Bietet gemeinsame Funktionalität für alle Cache-Adapter
 */
abstract class AbstractCache implements CacheInterface
{
    /** @var array Ungültige Zeichen für Cache-Schlüssel */
    protected const INVALID_KEY_CHARS = ['@', '{', '}', '(', ')', '/', '\\', ':', '?', '#', '%'];

    /** @var int Standard TTL in Sekunden, wenn keiner angegeben (30 Tage) */
    protected const DEFAULT_TTL = 2592000;

    /** @var string Präfix für alle Cache-Schlüssel */
    protected string $prefix;

    /** @var LoggerInterface Logger für Cache-Operationen */
    protected LoggerInterface $logger;

    /**
     * Erstellt eine neue Cache-Instanz
     *
     * @param string $prefix Präfix für alle Cache-Schlüssel
     * @param LoggerInterface|null $logger Optional: Logger für Cache-Operationen
     */
    public function __construct(
        string           $prefix = '',
        ?LoggerInterface $logger = null
    )
    {
        $this->prefix = $prefix;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritDoc}
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Erstellt einen vollständigen Cache-Schlüssel mit Präfix
     *
     * @param string $key Basis-Schlüssel
     * @return string Vollständiger Schlüssel
     * @throws InvalidArgumentException Wenn der Schlüssel ungültig ist
     */
    protected function prefixKey(string $key): string
    {
        $this->validateKey($key);

        if (empty($this->prefix)) {
            return $key;
        }

        return $this->prefix . ':' . $key;
    }

    /**
     * Validiert einen Cache-Schlüssel
     *
     * @param string $key Zu validierender Schlüssel
     * @return bool True, wenn der Schlüssel gültig ist
     * @throws InvalidArgumentException Wenn der Schlüssel ungültig ist
     */
    protected function validateKey(string $key): bool
    {
        if (empty($key)) {
            throw new InvalidArgumentException('Cache-Schlüssel darf nicht leer sein');
        }

        foreach (self::INVALID_KEY_CHARS as $char) {
            if (str_contains($key, $char)) {
                throw new InvalidArgumentException(
                    "Cache-Schlüssel enthält ungültiges Zeichen: '$char'"
                );
            }
        }

        return true;
    }

    /**
     * Berechnet die Ablaufzeit für einen TTL-Wert
     *
     * @param int|null $ttl Time-to-live in Sekunden
     * @return int UNIX-Timestamp für den Ablauf oder 0 für unbegrenzt
     */
    protected function calculateExpiry(?int $ttl): int
    {
        if ($ttl === null) {
            $ttl = self::DEFAULT_TTL;
        }

        if ($ttl <= 0) {
            return 0; // Unbegrenzt
        }

        return time() + $ttl;
    }

    /**
     * Prüft, ob ein Wert abgelaufen ist
     *
     * @param int $expiryTime Ablaufzeitpunkt als UNIX-Timestamp
     * @return bool True, wenn der Wert abgelaufen ist
     */
    protected function isExpired(int $expiryTime): bool
    {
        if ($expiryTime === 0) {
            return false; // Unbegrenzt
        }

        return time() > $expiryTime;
    }

    /**
     * Serialisiert einen Wert für die Speicherung
     *
     * @param mixed $value Zu serialisierender Wert
     * @return string Serialisierter Wert
     */
    protected function serialize(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * Deserialisiert einen gespeicherten Wert
     *
     * @param string $value Serialisierter Wert
     * @return mixed Deserialisierter Wert
     */
    protected function unserialize(string $value): mixed
    {
        return unserialize($value);
    }

    /**
     * Loggt eine Cache-Operation
     *
     * @param string $operation Name der Operation (get, set, delete, etc.)
     * @param string $key Cache-Schlüssel
     * @param mixed $result Ergebnis der Operation
     * @param array $context Zusätzlicher Kontext
     * @return void
     */
    protected function logOperation(string $operation, string $key, mixed $result, array $context = []): void
    {
        $logLevel = 'debug';
        $message = "Cache $operation: $key";

        $context = array_merge([
            'operation' => $operation,
            'key' => $key,
            'success' => $result !== false && $result !== null,
            'cache_type' => static::class,
        ], $context);

        $this->logger->log($logLevel, $message, $context);
    }
}