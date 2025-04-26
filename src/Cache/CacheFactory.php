<?php


namespace Src\Cache;

use InvalidArgumentException;
use Redis;
use RuntimeException;
use Src\Log\LoggerInterface;
use Throwable;

/**
 * Factory für Cache-Implementierungen
 *
 * Ermöglicht es, Cache-Instanzen basierend auf Konfiguration zu erstellen
 */
class CacheFactory
{
    /** @var array<string, string> Mapping von Cache-Typen zu Klassen */
    private array $cacheTypes = [
        'file' => FileCache::class,
        'redis' => RedisCache::class,
        'null' => NullCache::class,
    ];

    /** @var LoggerInterface Logger für Cache-Operationen */
    private LoggerInterface $logger;

    /**
     * Erstellt eine neue CacheFactory
     *
     * @param LoggerInterface $logger Logger für Cache-Operationen
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Erstellt einen Cache basierend auf dem angegebenen Typ
     *
     * @param string $type Cache-Typ ('file', 'redis', 'null')
     * @param array $config Konfiguration für den Cache
     * @return CacheInterface Cache-Instanz
     * @throws InvalidArgumentException Wenn der Cache-Typ ungültig ist
     */
    public function createCache(string $type, array $config = []): CacheInterface
    {
        if (!isset($this->cacheTypes[$type])) {
            throw new InvalidArgumentException(
                "Ungültiger Cache-Typ: $type. Erlaubte Typen: " . implode(', ', array_keys($this->cacheTypes))
            );
        }

        $cacheClass = $this->cacheTypes[$type];

        return match ($type) {
            'file' => $this->createFileCache($config),
            'redis' => $this->createRedisCache($config),
            'null' => new NullCache(),
            default => throw new InvalidArgumentException("Ungültiger Cache-Typ: $type"),
        };
    }

    /**
     * Erstellt einen FileCache
     *
     * @param array $config Konfiguration für den Cache
     * @return FileCache Cache-Instanz
     */
    private function createFileCache(array $config = []): FileCache
    {
        $cacheDir = $config['file']['dir'] ?? dirname(__DIR__, 2) . '/cache';
        $prefix = $config['prefix'] ?? '';
        $permissions = $config['file']['permissions'] ?? [
            'directory' => 0775,
            'file' => 0664,
        ];
        $useDeepDirectory = $config['file']['deep_directory'] ?? true;

        return new FileCache(
            $cacheDir,
            $prefix,
            $this->logger,
            $permissions['directory'],
            $permissions['file'],
            $useDeepDirectory
        );
    }

    /**
     * Erstellt einen RedisCache
     *
     * @param array $config Konfiguration für den Cache
     * @return RedisCache Cache-Instanz
     * @throws RuntimeException Wenn die Redis-Verbindung fehlschlägt
     */
    private function createRedisCache(array $config = []): RedisCache
    {
        $host = $config['redis']['host'] ?? '127.0.0.1';
        $port = $config['redis']['port'] ?? 6379;
        $timeout = $config['redis']['timeout'] ?? 0.0;
        $password = $config['redis']['password'] ?? null;
        $database = $config['redis']['database'] ?? 0;
        $prefix = $config['prefix'] ?? '';
        $persistent = $config['redis']['persistent'] ?? true;

        $redis = new Redis();
        $cache = new RedisCache($redis, $prefix, $this->logger);

        // Verbindung herstellen
        if ($persistent) {
            $cache->pconnect($host, $port, $timeout, $password, $database);
        } else {
            $cache->connect($host, $port, $timeout, $password, $database);
        }

        return $cache;
    }

    /**
     * Erstellt einen Standard-Cache basierend auf der Umgebung
     *
     * @param string $environment Umgebung ('development', 'production', etc.)
     * @param array $config Konfiguration für den Cache
     * @return CacheInterface Cache-Instanz
     */
    public function createDefaultCache(string $environment = 'development', array $config = []): CacheInterface
    {
        // In Produktionsumgebung Redis verwenden, falls konfiguriert, sonst File-Cache
        // In Entwicklungsumgebung: File-Cache
        if ($environment === 'production' && isset($config['redis']) && extension_loaded('redis')) {
            try {
                return $this->createRedisCache($config);
            } catch (Throwable $e) {
                $this->logger->warning(
                    "Konnte Redis-Cache nicht erstellen, fallback auf File-Cache: " . $e->getMessage(),
                    ['exception' => get_class($e)]
                );
                return $this->createFileCache($config);
            }
        }

        return $this->createFileCache($config);
    }

    /**
     * Registriert einen benutzerdefinierten Cache-Typ
     *
     * @param string $type Cache-Typ
     * @param string $class Cache-Klasse
     * @return self
     */
    public function registerCacheType(string $type, string $class): self
    {
        if (!class_exists($class) || !is_subclass_of($class, CacheInterface::class)) {
            throw new InvalidArgumentException("Klasse $class muss CacheInterface implementieren");
        }

        $this->cacheTypes[$type] = $class;

        return $this;
    }
}