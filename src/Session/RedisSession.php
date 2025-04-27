<?php


namespace Src\Session;

use Redis;
use RedisException;
use RuntimeException;
use SessionHandler;
use Src\Log\LoggerInterface;
use Throwable;

/**
 * Redis-basierte Session-Implementierung
 *
 * Verwendet Redis als Session-Handler für bessere Performance und Skalierbarkeit
 * Besonders nützlich in Umgebungen mit mehreren Servern (Load-Balancing)
 */
class RedisSession extends PhpSession
{
    /** @var Redis Redis-Instanz */
    protected Redis $redis;

    /** @var bool Verbindungsstatus */
    protected bool $connected = false;

    /**
     * Konstruktor
     *
     * @param array $config Redis- und Session-Konfiguration
     * @param LoggerInterface|null $logger Optional: Logger für Session-Operationen
     * @throws RuntimeException Wenn Redis-Erweiterung nicht verfügbar ist
     */
    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        // Prüfen, ob Redis-Erweiterung verfügbar ist
        if (!extension_loaded('redis')) {
            throw new RuntimeException('Redis-Erweiterung ist nicht verfügbar');
        }

        // Redis-Konfiguration mit Standardwerten zusammenführen
        $config = array_merge($this->getDefaultRedisConfig(), $config);

        // Übergeordneten Konstruktor aufrufen
        parent::__construct($config, $logger);

        // Redis-Instanz erstellen
        $this->redis = new Redis();

        // Wenn autoconnect aktiviert ist, Verbindung herstellen
        if ($config['redis']['autoconnect'] ?? true) {
            $this->connect();
        }
    }

    /**
     * Gibt die Redis-Standardkonfiguration zurück
     *
     * @return array Redis-Konfiguration
     */
    protected function getDefaultRedisConfig(): array
    {
        return [
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 0.0,
                'persistent' => true,
                'auth' => null,
                'database' => 0,
                'prefix' => 'session:',
                'autoconnect' => true,
                'retry_interval' => 100,     // Millisekunden
                'retry_attempts' => 3,
                'lock_ttl' => 60,            // Sekunden
                'lock_timeout' => 10,        // Sekunden
                'lock_wait' => 20000,        // Mikrosekunden
                'read_timeout' => 0.0,
                'serializer' => Redis::SERIALIZER_PHP,
                'compression' => false,
                'compression_level' => 6,
                'scan_retry' => 3
            ]
        ];
    }

    /**
     * Stellt eine Verbindung zu Redis her
     *
     * @return bool True bei Erfolg
     * @throws RuntimeException Bei Verbindungsfehlern
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        $redisConfig = $this->config['redis'] ?? [];
        $host = $redisConfig['host'] ?? '127.0.0.1';
        $port = $redisConfig['port'] ?? 6379;
        $timeout = $redisConfig['timeout'] ?? 0.0;
        $persistent = $redisConfig['persistent'] ?? true;
        $retryAttempts = $redisConfig['retry_attempts'] ?? 3;
        $retryInterval = $redisConfig['retry_interval'] ?? 100;

        // Verbindungsversuche mit Retry-Mechanismus
        $lastException = null;

        for ($attempt = 0; $attempt < $retryAttempts; $attempt++) {
            try {
                // Persistent oder nicht-persistent verbinden
                if ($persistent) {
                    $connected = $this->redis->pconnect($host, $port, $timeout, "session-{$this->namespace}");
                } else {
                    $connected = $this->redis->connect($host, $port, $timeout);
                }

                if (!$connected) {
                    throw new RuntimeException("Konnte keine Verbindung zu Redis herstellen: {$this->redis->getLastError()}");
                }

                // Authentifizierung, falls konfiguriert
                $auth = $redisConfig['auth'] ?? null;
                if ($auth !== null) {
                    if (!$this->redis->auth($auth)) {
                        throw new RuntimeException('Redis-Authentifizierung fehlgeschlagen');
                    }
                }

                // Datenbank auswählen
                $database = $redisConfig['database'] ?? 0;
                if ($database > 0 && !$this->redis->select($database)) {
                    throw new RuntimeException("Konnte Redis-Datenbank {$database} nicht auswählen");
                }

                // Serializer einstellen, falls verfügbar
                $serializer = $redisConfig['serializer'] ?? Redis::SERIALIZER_PHP;
                if (method_exists($this->redis, 'setSerializer')) {
                    $this->redis->setSerializer($serializer);
                }

                // Kompression, falls konfiguriert und unterstützt
                $compression = $redisConfig['compression'] ?? false;
                if ($compression && method_exists($this->redis, 'setOption') && defined('Redis::OPT_COMPRESSION')) {
                    $level = $redisConfig['compression_level'] ?? 6;
                    $this->redis->setOption(Redis::OPT_COMPRESSION, $level);
                }

                // Read-Timeout setzen
                $readTimeout = $redisConfig['read_timeout'] ?? 0.0;
                if (method_exists($this->redis, 'setOption') && defined('Redis::OPT_READ_TIMEOUT')) {
                    $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);
                }

                // Session-Handler registrieren
                $this->registerSessionHandler();

                $this->connected = true;
                $this->logger->info('Verbindung zu Redis hergestellt', [
                    'host' => $host,
                    'port' => $port,
                    'database' => $database,
                    'persistent' => $persistent
                ]);

                return true;
            } catch (Throwable $e) {
                $lastException = $e;
                $this->logger->warning('Redis-Verbindungsversuch fehlgeschlagen', [
                    'attempt' => $attempt + 1,
                    'host' => $host,
                    'port' => $port,
                    'error' => $e->getMessage()
                ]);

                // Kurz warten vor dem nächsten Versuch
                if ($attempt < $retryAttempts - 1) {
                    usleep($retryInterval * 1000);
                }
            }
        }

        // Wenn alle Versuche fehlgeschlagen sind
        throw new RuntimeException(
            "Konnte nach {$retryAttempts} Versuchen keine Verbindung zu Redis herstellen: " .
            ($lastException ? $lastException->getMessage() : 'Unbekannter Fehler'),
            0,
            $lastException
        );
    }

    /**
     * Registriert den Redis-Session-Handler
     *
     * @return bool True bei Erfolg
     */
    protected function registerSessionHandler(): bool
    {
        $redisConfig = $this->config['redis'] ?? [];
        $prefix = $redisConfig['prefix'] ?? 'session:';
        $ttl = $this->config['gc_maxlifetime'] ?? 1440;
        $lockTtl = $redisConfig['lock_ttl'] ?? 60;

        // Session-Handler-Objekt erstellen
        $sessionHandler = new SessionHandler();

        // Handler mittels session_set_save_handler registrieren
        $success = session_set_save_handler(
        // open - Verbindung bereits hergestellt
            function ($savePath, $sessionName) {
                return true;
            },

            // close - Nichts zu tun
            function () {
                return true;
            },

            // read - Daten aus Redis lesen
            function ($id) use ($prefix, $ttl, $lockTtl, $redisConfig) {
                $key = $prefix . $id;
                $lockKey = $key . ':lock';
                $lockTimeout = $redisConfig['lock_timeout'] ?? 10;
                $lockWait = $redisConfig['lock_wait'] ?? 20000;
                $scanRetry = $redisConfig['scan_retry'] ?? 3;

                // Nur bei Schreibzugriffen Lock verwenden
                if (!$this->readOnly) {
                    // Versuchen, den Lock zu erwerben
                    $attempts = 0;
                    $maxAttempts = ($lockTimeout * 1000000) / $lockWait;

                    while ($attempts < $maxAttempts) {
                        // NX = nur setzen, wenn noch nicht existiert
                        // PX = Millisekunden-Timeout
                        if ($this->redis->set($lockKey, 1, ['NX', 'PX' => $lockTtl * 1000])) {
                            break;
                        }

                        $attempts++;
                        usleep($lockWait);
                    }

                    if ($attempts >= $maxAttempts) {
                        $this->logger->warning('Session-Lock konnte nicht erworben werden', [
                            'id' => $id,
                            'attempts' => $attempts
                        ]);
                    }
                }

                // Session-Daten abrufen (mehrere Versuche bei Netzwerkproblemen)
                $data = '';
                $error = null;

                for ($attempt = 0; $attempt < $scanRetry; $attempt++) {
                    try {
                        $data = $this->redis->get($key) ?: '';
                        $error = null;
                        break;
                    } catch (RedisException $e) {
                        $error = $e;
                        usleep(5000); // 5ms warten vor dem nächsten Versuch
                    }
                }

                if ($error !== null) {
                    $this->logger->error('Fehler beim Lesen der Session-Daten aus Redis', [
                        'id' => $id,
                        'error' => $error->getMessage()
                    ]);
                }

                // TTL verlängern
                if ($data !== '') {
                    $this->redis->expire($key, $ttl);
                }

                return $data;
            },

            // write - Daten in Redis schreiben
            function ($id, $data) use ($prefix, $ttl) {
                $key = $prefix . $id;
                $lockKey = $key . ':lock';

                try {
                    // Session-Daten in Redis speichern
                    $result = $this->redis->setex($key, $ttl, $data);

                    if (!$result) {
                        $this->logger->error('Fehler beim Schreiben der Session-Daten in Redis', [
                            'id' => $id,
                            'error' => $this->redis->getLastError()
                        ]);
                        return false;
                    }

                    // Lock freigeben
                    $this->redis->del($lockKey);

                    return true;
                } catch (RedisException $e) {
                    $this->logger->error('Exception beim Schreiben der Session-Daten in Redis', [
                        'id' => $id,
                        'error' => $e->getMessage()
                    ]);

                    // Lock trotzdem freigeben versuchen
                    try {
                        $this->redis->del($lockKey);
                    } catch (Throwable $e) {
                        // Ignorieren
                    }

                    return false;
                }
            },

            // destroy - Session aus Redis löschen
            function ($id) use ($prefix) {
                $key = $prefix . $id;
                $lockKey = $key . ':lock';

                try {
                    $this->redis->del([$key, $lockKey]);
                    return true;
                } catch (RedisException $e) {
                    $this->logger->error('Fehler beim Löschen der Session aus Redis', [
                        'id' => $id,
                        'error' => $e->getMessage()
                    ]);
                    return false;
                }
            },

            // gc - Garbage Collection (wird von Redis automatisch mittels TTL erledigt)
            function ($maxlifetime) {
                return true;
            }
        );

        // Session-Handler-Status überprüfen
        if (!$success) {
            $this->logger->error('Konnte Redis-Session-Handler nicht registrieren');
            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function closeAndKeepUsing(): bool
    {
        if (!$this->started) {
            return false;
        }

        // Im Read-Only-Modus ist die Session bereits geschlossen
        if ($this->readOnly) {
            return true;
        }

        // Bei Redis können wir die Session-Daten schreiben und den Lock freigeben
        $sessionId = session_id();
        if (!empty($sessionId)) {
            // Lock-Key bestimmen
            $redisConfig = $this->config['redis'] ?? [];
            $prefix = $redisConfig['prefix'] ?? 'session:';
            $lockKey = $prefix . $sessionId . ':lock';

            // Lock freigeben
            try {
                $this->redis->del($lockKey);
            } catch (Throwable $e) {
                $this->logger->warning('Konnte Redis-Session-Lock nicht freigeben', [
                    'id' => $sessionId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Session schließen
        session_write_close();
        $this->readOnly = true;

        $this->logger->debug('Redis-Session geschlossen (Read-Only-Modus)');

        return true;
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

    /**
     * Implementiert einen spezialisierten Locking-Mechanismus für Redis
     *
     * @param string $key Lock-Key
     * @param int $lockTime Lock-Dauer in Sekunden
     * @param int $timeout Gesamtzeit für Versuche in Sekunden
     * @param int $wait Zeit zwischen Versuchen in Mikrosekunden
     * @return bool True wenn Lock erfolgreich erworben wurde
     */
    public function acquireLock(string $key, int $lockTime = 60, int $timeout = 5, int $wait = 20000): bool
    {
        if (!$this->connected) {
            $this->connect();
        }

        $lockKey = "lock:{$key}";
        $token = bin2hex(random_bytes(16)); // Eindeutiger Token für diesen Lock

        $attempts = 0;
        $maxAttempts = ($timeout * 1000000) / $wait;
        $startTime = microtime(true);

        while ($attempts < $maxAttempts) {
            // Versuchen, den Lock zu erwerben
            if ($this->redis->set($lockKey, $token, ['NX', 'PX' => $lockTime * 1000])) {
                $this->logger->debug("Lock erworben", [
                    'key' => $key,
                    'token' => $token,
                    'attempts' => $attempts,
                    'time' => microtime(true) - $startTime
                ]);

                // Lock-Token in der Session speichern, um ihn später freigeben zu können
                $locks = $this->get('_locks', []);
                $locks[$key] = $token;
                $this->set('_locks', $locks);

                return true;
            }

            $attempts++;
            usleep($wait);
        }

        $this->logger->warning("Lock konnte nicht erworben werden", [
            'key' => $key,
            'attempts' => $attempts,
            'timeout' => $timeout
        ]);

        return false;
    }

    /**
     * Gibt einen Lock frei
     *
     * @param string $key Lock-Key
     * @return bool True bei Erfolg
     */
    public function releaseLock(string $key): bool
    {
        if (!$this->connected) {
            return false;
        }

        $locks = $this->get('_locks', []);
        $token = $locks[$key] ?? null;

        if ($token === null) {
            $this->logger->warning("Lock-Token nicht gefunden", [
                'key' => $key
            ]);
            return false;
        }

        $lockKey = "lock:{$key}";

        // Lua-Script für atomare Überprüfung und Löschen
        $script = <<<LUA
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
else
    return 0
end
LUA;

        try {
            $result = $this->redis->eval($script, [$lockKey, $token], 1);

            // Lock aus der Session entfernen
            unset($locks[$key]);
            $this->set('_locks', $locks);

            if ($result) {
                $this->logger->debug("Lock freigegeben", [
                    'key' => $key,
                    'token' => $token
                ]);
                return true;
            } else {
                $this->logger->warning("Lock konnte nicht freigegeben werden (Token nicht übereinstimmend)", [
                    'key' => $key,
                    'token' => $token
                ]);
                return false;
            }
        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Freigeben des Locks", [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}