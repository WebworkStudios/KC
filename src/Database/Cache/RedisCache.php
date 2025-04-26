<?php

namespace Src\Database\Cache;

use Redis;
use RedisException;
use RuntimeException;
use Src\Log\LoggerInterface;
use Src\Log\NullLogger;
use Throwable;

/**
 * Redis-basierte Cache-Implementierung für das Datenbank-Caching
 *
 * Bietet schnelles Caching von Datenbankabfrageergebnissen mit Redis und
 * unterstützt Tag-basierte Invalidierung für effiziente Cache-Verwaltung
 */
class RedisCache implements CacheInterface
{
    /** @var Redis Redis-Instanz */
    private Redis $redis;

    /** @var bool Gibt an, ob der Verbindungsversuch bereits erfolgt ist */
    private bool $connected = false;

    /** @var string Präfix für alle Cache-Schlüssel */
    private string $prefix;

    /** @var LoggerInterface Logger für Cache-Operationen */
    private LoggerInterface $logger;

    /** @var int Standard TTL in Sekunden, wenn keiner angegeben (1 Tag) */
    private const DEFAULT_TTL = 86400;

    /**
     * Erstellt eine neue RedisCache-Instanz
     *
     * @param Redis|null $redis Optional: Redis-Instanz (wird automatisch erstellt, wenn nicht übergeben)
     * @param string $prefix Präfix für alle Cache-Schlüssel
     * @param LoggerInterface|null $logger Optional: Logger für Cache-Operationen
     * @throws RuntimeException Wenn die Redis-Erweiterung nicht verfügbar ist
     */
    public function __construct(
        ?Redis $redis = null,
        string $prefix = 'db_cache:',
        ?LoggerInterface $logger = null
    ) {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('Die Redis-Erweiterung ist nicht verfügbar');
        }

        $this->redis = $redis ?? new Redis();
        $this->prefix = $prefix;
        $this->logger = $logger ?? new NullLogger();

        $this->logger->debug("Database RedisCache initialisiert", [
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
        string $host = '127.0.0.1',
        int $port = 6379,
        float $timeout = 0.0,
        ?string $password = null,
        int $database = 0
    ): bool {
        try {
            $connectResult = $this->redis->connect($host, $port, $timeout);

            if ($connectResult) {
                if ($password !== null) {
                    if (!$this->redis->auth($password)) {
                        throw new RuntimeException('Redis-Authentifizierung fehlgeschlagen');
                    }
                }

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
     * Erstellt eine persistente Verbindung zum Redis-Server
     *
     * @param string $host Redis-Host
     * @param int $port Redis-Port
     * @param float $timeout Verbindungs-Timeout in Sekunden
     * @param string|null $password Optional: Passwort für die Authentifizierung
     * @param int $database Redis-Datenbank-Index
     * @return bool True bei erfolgreicher Verbindung
     * @throws RuntimeException Wenn keine Verbindung hergestellt werden kann
     */
    public function pconnect(
        string $host = '127.0.0.1',
        int $port = 6379,
        float $timeout = 0.0,
        ?string $password = null,
        int $database = 0
    ): bool {
        try {
            $connectResult = $this->redis->pconnect($host, $port, $timeout);

            if ($connectResult) {
                if ($password !== null) {
                    if (!$this->redis->auth($password)) {
                        throw new RuntimeException('Redis-Authentifizierung fehlgeschlagen');
                    }
                }

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
    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        try {
            $this->ensureConnected();

            $prefixedKey = $this->prefixKey($key);
            $serialized = $this->serialize($value);

            // Pipeline für bessere Performance
            $this->redis->multi();

            // Hauptwert setzen
            if ($ttl === null) {
                $ttl = self::DEFAULT_TTL;
            }

            if ($ttl <= 0) {
                $this->redis->set($prefixedKey, $serialized);
            } else {
                $this->redis->setex($prefixedKey, $ttl, $serialized);
            }

            // Tags verarbeiten
            if (!empty($tags)) {
                // Schlüssel zu jedem Tag hinzufügen
                foreach ($tags as $tag) {
                    $tagKey = $this->getTagKey($tag);
                    $this->redis->sAdd($tagKey, $prefixedKey);

                    // Sicherstellen, dass der Tag-Set nicht verfällt, wenn er verwendet wird
                    $this->redis->persist($tagKey);
                }

                // Tags für Schlüssel speichern, um bei Löschung aufräumen zu können
                $keyTagsKey = $this->getKeyTagsKey($prefixedKey);
                $this->redis->sAdd($keyTagsKey, ...$tags);

                // TTL für Key-Tags identisch zum Hauptschlüssel setzen
                if ($ttl > 0) {
                    $this->redis->expire($keyTagsKey, $ttl);
                }
            }

            $results = $this->redis->exec();
            $success = !in_array(false, $results, true);

            $this->logger->debug("Redis Cache-Set ausgeführt", [
                'key' => $key,
                'ttl' => $ttl,
                'tags' => $tags,
                'success' => $success
            ]);

            return $success;
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
    public function get(string $key): mixed
    {
        try {
            $this->ensureConnected();

            $prefixedKey = $this->prefixKey($key);
            $value = $this->redis->get($prefixedKey);

            if ($value === false) {
                $this->logger->debug("Cache-Miss für Schlüssel", [
                    'key' => $key
                ]);
                return null;
            }

            $result = $this->unserialize($value);

            $this->logger->debug("Cache-Hit für Schlüssel", [
                'key' => $key
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Lesen aus dem Redis-Cache: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return null;
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
            $result = (bool)$this->redis->exists($prefixedKey);

            $this->logger->debug("Cache-Existenzprüfung", [
                'key' => $key,
                'exists' => $result
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler bei Existenzprüfung im Redis-Cache: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return false;
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

            // Tags für den Schlüssel finden und aufräumen
            $keyTagsKey = $this->getKeyTagsKey($prefixedKey);
            $tags = $this->redis->sMembers($keyTagsKey);

            $this->redis->multi();

            // Schlüssel aus allen zugehörigen Tag-Sets entfernen
            foreach ($tags as $tag) {
                $tagKey = $this->getTagKey($tag);
                $this->redis->sRem($tagKey, $prefixedKey);
            }

            // Tag-Zuordnungsschlüssel löschen
            if (!empty($tags)) {
                $this->redis->del($keyTagsKey);
            }

            // Hauptschlüssel löschen
            $this->redis->del($prefixedKey);

            $results = $this->redis->exec();
            $success = !in_array(false, $results, true);

            $this->logger->debug("Cache-Schlüssel gelöscht", [
                'key' => $key,
                'tags_cleaned' => count($tags),
                'success' => $success
            ]);

            return $success;
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

                $this->logger->info("Gesamte Redis-Datenbank geleert", [
                    'result' => $result
                ]);

                return (bool)$result;
            }

            // Nur Schlüssel mit dem definierten Präfix löschen
            $pattern = $this->prefix . '*';
            $keys = $this->redis->keys($pattern);

            if (empty($keys)) {
                $this->logger->info("Keine Schlüssel zum Leeren gefunden", [
                    'pattern' => $pattern
                ]);

                return true;
            }

            // Löschen aller gefundenen Schlüssel
            $result = $this->redis->del($keys) > 0;

            $this->logger->info("Cache geleert", [
                'pattern' => $pattern,
                'deleted_keys' => count($keys),
                'success' => $result
            ]);

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
    public function invalidateByTag(string $tag): bool
    {
        try {
            $this->ensureConnected();

            // Schlüssel-Set für den Tag holen
            $tagKey = $this->getTagKey($tag);
            $keys = $this->redis->sMembers($tagKey);

            if (empty($keys)) {
                $this->logger->debug("Keine Schlüssel für Tag gefunden", [
                    'tag' => $tag
                ]);

                return true;
            }

            $this->redis->multi();

            // Alle Schlüssel löschen
            if (!empty($keys)) {
                $this->redis->del(...$keys);

                // Gleichzeitig alle Tag-Zuordnungen löschen
                foreach ($keys as $key) {
                    $keyTagsKey = $this->getKeyTagsKey($key);
                    $this->redis->del($keyTagsKey);
                }
            }

            // Tag-Set selbst löschen
            $this->redis->del($tagKey);

            $results = $this->redis->exec();
            $success = !in_array(false, $results, true);

            $this->logger->info("Cache nach Tag invalidiert", [
                'tag' => $tag,
                'invalidated_keys' => count($keys),
                'success' => $success
            ]);

            return $success;
        } catch (Throwable $e) {
            $this->logger->error("Fehler bei Tag-Invalidierung im Redis-Cache: " . $e->getMessage(), [
                'tag' => $tag,
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
    {
        // Prüfen, ob der Wert bereits im Cache ist
        $cachedValue = $this->get($key);

        if ($cachedValue !== null) {
            $this->logger->debug("Cache-Hit bei remember()", [
                'key' => $key
            ]);

            return $cachedValue;
        }

        // Wert nicht im Cache, Callback ausführen
        try {
            $this->logger->debug("Cache-Miss bei remember(), führe Callback aus", [
                'key' => $key
            ]);

            $value = $callback();

            // Ergebnis cachen
            $this->set($key, $value, $ttl, $tags);

            return $value;
        } catch (Throwable $e) {
            $this->logger->error("Fehler bei remember()-Callback: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            // Exception weiterleiten
            throw $e;
        }
    }

    /**
     * Fügt ein Präfix zum Cache-Schlüssel hinzu
     *
     * @param string $key Original-Schlüssel
     * @return string Präfixierter Schlüssel
     */
    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Erzeugt einen Schlüssel für ein Tag-Set
     *
     * @param string $tag Tag-Name
     * @return string Redis-Schlüssel für das Tag-Set
     */
    private function getTagKey(string $tag): string
    {
        return $this->prefix . 'tag:' . $tag;
    }

    /**
     * Erzeugt einen Schlüssel für die Tag-Zuordnungen eines Cache-Eintrags
     *
     * @param string $key Präfixierter Cache-Schlüssel
     * @return string Schlüssel für die Tag-Zuordnungen
     */
    private function getKeyTagsKey(string $key): string
    {
        return $key . ':tags';
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

        try {
            $this->redis->ping();
        } catch (RedisException $e) {
            throw new RuntimeException('Redis-Verbindung wurde unterbrochen: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Serialisiert einen Wert für die Speicherung
     *
     * @param mixed $value Zu serialisierender Wert
     * @return string Serialisierter Wert
     */
    private function serialize(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * Deserialisiert einen gespeicherten Wert
     *
     * @param string $value Serialisierter Wert
     * @return mixed Deserialisierter Wert
     */
    private function unserialize(string $value): mixed
    {
        return unserialize($value);
    }

    /**
     * Gibt die zugrunde liegende Redis-Instanz zurück
     *
     * @return Redis Redis-Instanz
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }

    /**
     * Setzt eine neue TTL für einen bestehenden Schlüssel
     *
     * @param string $key Der Cache-Schlüssel
     * @param int $ttl Neue TTL in Sekunden
     * @return bool True bei Erfolg
     */
    public function setTtl(string $key, int $ttl): bool
    {
        try {
            $this->ensureConnected();

            $prefixedKey = $this->prefixKey($key);

            // Prüfen, ob der Schlüssel existiert
            if (!$this->redis->exists($prefixedKey)) {
                $this->logger->debug("Schlüssel nicht gefunden für TTL-Änderung", [
                    'key' => $key
                ]);
                return false;
            }

            $result = $this->redis->expire($prefixedKey, $ttl);

            // Tag-Zuordnungen ebenfalls aktualisieren
            $keyTagsKey = $this->getKeyTagsKey($prefixedKey);
            if ($this->redis->exists($keyTagsKey)) {
                $this->redis->expire($keyTagsKey, $ttl);
            }

            $this->logger->debug("Cache-TTL aktualisiert", [
                'key' => $key,
                'ttl' => $ttl,
                'success' => $result
            ]);

            return (bool)$result;
        } catch (Throwable $e) {
            $this->logger->error("Fehler bei TTL-Änderung im Redis-Cache: " . $e->getMessage(), [
                'key' => $key,
                'ttl' => $ttl,
                'exception' => get_class($e)
            ]);

            return false;
        }
    }

    /**
     * Gibt die verbleibende TTL für einen Schlüssel zurück
     *
     * @param string $key Der Cache-Schlüssel
     * @return int|null Verbleibende TTL in Sekunden oder null wenn Schlüssel nicht existiert
     */
    public function getTtl(string $key): ?int
    {
        try {
            $this->ensureConnected();

            $prefixedKey = $this->prefixKey($key);
            $ttl = $this->redis->ttl($prefixedKey);

            // -2 bedeutet, der Schlüssel existiert nicht
            // -1 bedeutet, der Schlüssel hat keine Ablaufzeit
            if ($ttl === -2) {
                return null;
            }

            return $ttl;
        } catch (Throwable $e) {
            $this->logger->error("Fehler bei TTL-Abruf im Redis-Cache: " . $e->getMessage(), [
                'key' => $key,
                'exception' => get_class($e)
            ]);

            return null;
        }
    }

    /**
     * Gibt Statistiken zum Cache zurück
     *
     * @return array Cache-Statistiken
     */
    public function getStats(): array
    {
        try {
            $this->ensureConnected();

            $info = $this->redis->info();
            $dbSize = $this->redis->dbSize();

            $prefixPattern = $this->prefix . '*';
            $prefixKeys = $this->redis->keys($prefixPattern);
            $prefixCount = count($prefixKeys);

            // Tag-Statistiken sammeln
            $tagPattern = $this->prefix . 'tag:*';
            $tagKeys = $this->redis->keys($tagPattern);
            $tagStats = [];

            foreach ($tagKeys as $tagKey) {
                $tag = substr($tagKey, strlen($this->prefix . 'tag:'));
                $count = $this->redis->sCard($tagKey);
                $tagStats[$tag] = $count;
            }

            return [
                'total_keys' => $dbSize,
                'prefixed_keys' => $prefixCount,
                'memory_used' => $info['used_memory_human'] ?? 'unknown',
                'uptime' => $info['uptime_in_seconds'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 'unknown',
                'tags' => $tagStats
            ];
        } catch (Throwable $e) {
            $this->logger->error("Fehler bei Stats-Abruf im Redis-Cache: " . $e->getMessage(), [
                'exception' => get_class($e)
            ]);

            return [
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ];
        }
    }
}