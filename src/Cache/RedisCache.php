<?php

namespace Src\Cache;

use Redis;
use RedisException;
use RuntimeException;
use Src\Log\LoggerInterface;
use Throwable;

/**
 * Redis-basierte Cache-Implementierung
 *
 * Speichert Cache-Einträge in einem Redis-Server
 */
class RedisCache extends AbstractCache
{
    /** @var Redis Redis-Instanz */
    private Redis $redis;

    /** @var bool Gibt an, ob der Verbindungsversuch bereits erfolgt ist */
    private bool $connected = false;

    /**
     * Erstellt eine neue RedisCache-Instanz
     *
     * @param Redis|null $redis Optional: Redis-Instanz (wird automatisch erstellt, wenn nicht übergeben)
     * @param string $prefix Präfix für alle Cache-Schlüssel
     * @param LoggerInterface|null $logger Optional: Logger für Cache-Operationen
     * @throws RuntimeException Wenn die Redis-Erweiterung nicht verfügbar ist
     */
    public function __construct(
        ?Redis           $redis = null,
        string           $prefix = '',
        ?LoggerInterface $logger = null
    )
    {
        parent::__construct($prefix, $logger);

        if (!extension_loaded('redis')) {
            throw new RuntimeException('Die Redis-Erweiterung ist nicht verfügbar');
        }

        $this->redis = $redis ?? new Redis();

        $this->logger->debug("RedisCache initialisiert", [
            'prefix' => $this->prefix
        ]);
    }

    /**
     * Verbindet mit dem Redis-Server
     *
     * @param string $host Redis-Host
     * @param int $port Redis-Port
     * @param float $timeout Verbindungs-Timeout in Sekunden
     * @param string|null $password Optional: Passwort für die Authentifizierung
     * @param int $database Redis-Datenbank-Index
     * @return bool True bei erfolgreicher Verbindung
     * @throws RuntimeException Wenn keine Verbindung hergestellt werden kann
     */
    public function connect(
        string  $host = '127.0.0.1',
        int     $port = 6379,
        float   $timeout = 0.0,
        ?string $password = null,
        int     $database = 0
    ): bool
    {
        try {
            $connectResult = $this->redis->connect($host, $port, $timeout);

            if ($connectResult) {
                // Authentifizieren, falls Passwort angegeben
                if ($password !== null) {
                    if (!$this->redis->auth($password)) {
                        throw new RuntimeException('Redis-Authentifizierung fehlgeschlagen');
                    }
                }

                // Datenbank auswählen
                if ($database > 0) {
                    if (!$this->redis->select($database)) {
                        throw new RuntimeException("Konnte Redis-Datenbank $database nicht auswählen");
                    }
                }

                $this->connected = true;

                $this->logger->info("Mit Redis-Server verbunden", [
                    'host' => $host,
                    'port' => $port,
                    'database' => $database
                ]);

                return true;
            }

            throw new RuntimeException("Konnte keine Verbindung zum Redis-Server herstellen");
        } catch (RedisException $e) {
            $this->logger->error("Redis-Verbindungsfehler: " . $e->getMessage(), [
                'host' => $host,
                'port' => $port,
                'exception' => get_class($e)
            ]);

            throw new RuntimeException("Redis-Verbindungsfehler: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verbindet über TCP mit einem Redis-Server.
     * Wrapper für die connect()-Methode mit Standard-Werten
     *
     * @param string $host Redis-Host
     * @param int $port Redis-Port
     * @param float $timeout Verbindungs-Timeout in Sekunden
     * @param string|null $password Optional: Passwort für die Authentifizierung
     * @param int $database Redis-Datenbank-Index
     * @return bool True bei erfolgreicher Verbindung
     */
    public function pconnect(
        string  $host = '127.0.0.1',
        int     $port = 6379,
        float   $timeout = 0.0,
        ?string $password = null,
        int     $database = 0
    ): bool
    {
        try {
            $connectResult = $this->redis->pconnect($host, $port, $timeout);

            if ($connectResult) {
                // Authentifizieren, falls Passwort angegeben
                if ($password !== null) {
                    if (!$this->redis->auth($password)) {
                        throw new RuntimeException('Redis-Authentifizierung fehlgeschlagen');
                    }
                }

                // Datenbank auswählen
                if ($database > 0) {
                    if (!$this->redis->select($database)) {
                        throw new RuntimeException("Konnte Redis-Datenbank $database nicht auswählen");
                    }
                }

                $this->connected = true;

                $this->logger->info("Mit Redis-Server persistent verbunden", [
                    'host' => $host,
                    'port' => $port,
                    'database' => $database
                ]);

                return true;
            }

            throw new RuntimeException("Konnte keine persistente Verbindung zum Redis-Server herstellen");
        } catch (RedisException $e) {
            $this->logger->error("Redis-Verbindungsfehler: " . $e->getMessage(), [
                'host' => $host,
                'port' => $port,
                'exception' => get_class($e)
            ]);

            throw new RuntimeException("Redis-Verbindungsfehler: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $this->ensureConnected();

            $prefixedKey = $this->prefixKey($key);
            $value = $this->redis->get($prefixedKey);

            if ($value === false) {
                $this->logOperation('get', $key, false, ['reason' => 'not_found']);
                return $default;
            }

            $result = $this->unserialize($value);
            $this->logOperation('get', $key, true, ['hit' => true]);

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Lesen aus dem Redis-Cache: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return $default;
        }
    }

    /**
     * Stellt sicher, dass eine Verbindung zum Redis-Server besteht
     *
     * @throws RuntimeException Wenn keine Verbindung hergestellt werden kann
     */
    private function ensureConnected(): void
    {
        if (!$this->connected) {
            throw new RuntimeException('Keine Verbindung zum Redis-Server hergestellt. Rufen Sie connect() oder pconnect() auf.');
        }

        if (!$this->redis->ping()) {
            throw new RuntimeException('Redis-Verbindung wurde unterbrochen');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): bool
    {
        try {
            $this->ensureConnected();

            $prefixedKey = $this->prefixKey($key);
            $result = $this->redis->del($prefixedKey) > 0;

            $this->logOperation('delete', $key, $result);

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Löschen aus dem Redis-Cache: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        try {
            $this->ensureConnected();

            if (empty($this->prefix)) {
                // Wenn kein Präfix definiert ist, gesamte Datenbank leeren
                $result = $this->redis->flushDB();
                $this->logOperation('clear', 'all', $result, ['type' => 'flushDB']);
                return (bool)$result;
            }

            // Nur Schlüssel mit dem definierten Präfix löschen
            $pattern = $this->prefix . ':*';
            $keys = $this->redis->keys($pattern);

            if (empty($keys)) {
                $this->logOperation('clear', $pattern, true, ['count' => 0]);
                return true;
            }

            // Löschen aller gefundenen Schlüssel
            $result = $this->redis->del($keys) > 0;
            $this->logOperation('clear', $pattern, $result, ['count' => count($keys)]);

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Leeren des Redis-Caches: " . $e->getMessage(), [
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        try {
            $this->ensureConnected();

            $prefixedKey = $this->prefixKey($key);
            $result = $this->redis->exists($prefixedKey);

            $this->logOperation('has', $key, (bool)$result);

            return (bool)$result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Prüfen des Redis-Cache-Schlüssels: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        try {
            $this->ensureConnected();

            $prefixedKeys = [];
            $keyMap = []; // Mapping von prefixedKey zurück zu originalKey

            // Schlüssel mit Präfix versehen
            foreach ($keys as $key) {
                $prefixedKey = $this->prefixKey($key);
                $prefixedKeys[] = $prefixedKey;
                $keyMap[$prefixedKey] = $key;
            }

            if (empty($prefixedKeys)) {
                return [];
            }

            $values = $this->redis->mGet($prefixedKeys);
            $result = [];

            foreach ($prefixedKeys as $index => $prefixedKey) {
                $originalKey = $keyMap[$prefixedKey];
                $value = $values[$index];

                if ($value === false) {
                    $result[$originalKey] = $default;
                } else {
                    $result[$originalKey] = $this->unserialize($value);
                }
            }

            $this->logOperation('getMultiple', implode(',', $keys), true, [
                'count' => count($keys),
                'hits' => count(array_filter($values, fn($v) => $v !== false))
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Multiple-Get aus dem Redis-Cache: " . $e->getMessage(), [
                'keys' => implode(',', $keys),
                'exception' => get_class($e)
            ]);

            // Fallback zur Einzelabfrage
            return parent::getMultiple($keys, $default);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        try {
            $this->ensureConnected();

            if (empty($values)) {
                return true;
            }

            // Pipeline für bessere Performance
            $this->redis->multi();

            foreach ($values as $key => $value) {
                $prefixedKey = $this->prefixKey($key);
                $serialized = $this->serialize($value);

                if ($ttl === null || $ttl <= 0) {
                    $this->redis->set($prefixedKey, $serialized);
                } else {
                    $this->redis->setex($prefixedKey, $ttl, $serialized);
                }
            }

            $results = $this->redis->exec();
            $success = !in_array(false, $results, true);

            $this->logOperation('setMultiple', implode(',', array_keys($values)), $success, [
                'count' => count($values),
                'ttl' => $ttl
            ]);

            return $success;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Multiple-Set in den Redis-Cache: " . $e->getMessage(), [
                'keys' => implode(',', array_keys($values)),
                'exception' => get_class($e)
            ]);

            // Fallback zur Einzeloperation
            return parent::setMultiple($values, $ttl);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            $this->ensureConnected();

            $prefixedKey = $this->prefixKey($key);
            $serialized = $this->serialize($value);

            if ($ttl === null) {
                $ttl = self::DEFAULT_TTL;
            }

            if ($ttl <= 0) {
                // Unbegrenzte Lebensdauer
                $result = $this->redis->set($prefixedKey, $serialized);
            } else {
                // Mit TTL setzen
                $result = $this->redis->setex($prefixedKey, $ttl, $serialized);
            }

            $this->logOperation('set', $key, $result, [
                'ttl' => $ttl,
                'size' => strlen($serialized)
            ]);

            return (bool)$result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Schreiben in den Redis-Cache: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMultiple(array $keys): bool
    {
        try {
            $this->ensureConnected();

            if (empty($keys)) {
                return true;
            }

            $prefixedKeys = array_map([$this, 'prefixKey'], $keys);
            $result = $this->redis->del($prefixedKeys) >= 0;

            $this->logOperation('deleteMultiple', implode(',', $keys), $result, [
                'count' => count($keys)
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Multiple-Delete aus dem Redis-Cache: " . $e->getMessage(), [
                'keys' => implode(',', $keys),
                'exception' => get_class($e)
            ]);

            // Fallback zur Einzeloperation
            return parent::deleteMultiple($keys);
        }
    }

    /**
     * Gibt die Redis-Instanz zurück
     *
     * @return Redis
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }
}