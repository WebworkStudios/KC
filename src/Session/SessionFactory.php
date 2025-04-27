<?php

namespace Src\Session;

use InvalidArgumentException;
use Src\Log\LoggerInterface;
use Throwable;

/**
 * Factory für Session-Implementierungen
 *
 * Erstellt verschiedene Session-Handler basierend auf der Konfiguration
 */
class SessionFactory
{
    /** @var array<string, string> Mapping von Session-Typen zu Klassen */
    private array $sessionTypes = [
        'php' => PhpSession::class,
        'redis' => RedisSession::class
    ];

    /** @var LoggerInterface Logger für Session-Operationen */
    private LoggerInterface $logger;

    /**
     * Erstellt eine neue SessionFactory
     *
     * @param LoggerInterface $logger Logger für Session-Operationen
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Erstellt einen Standard-Session basierend auf der Umgebung
     *
     * @param string $environment Umgebung ('development', 'production', etc.)
     * @param array $config Konfiguration für die Session
     * @return SessionInterface Session-Instanz
     */
    public function createDefaultSession(string $environment = 'development', array $config = []): SessionInterface
    {
        // In Produktionsumgebung Redis verwenden, falls konfiguriert
        if ($environment === 'production' && isset($config['redis']) && extension_loaded('redis')) {
            try {
                return $this->createSession('redis', $config);
            } catch (Throwable $e) {
                $this->logger->warning(
                    "Konnte Redis-Session nicht erstellen, Fallback auf PHP-Session: " . $e->getMessage(),
                    ['exception' => get_class($e)]
                );
            }
        }

        // Standard-PHP-Session verwenden
        return $this->createSession('php', $config);
    }

    /**
     * Erstellt eine Session-Instanz basierend auf dem angegebenen Typ
     *
     * @param string $type Session-Typ ('php', 'redis')
     * @param array $config Konfiguration für die Session
     * @return SessionInterface Session-Instanz
     * @throws InvalidArgumentException Wenn der Session-Typ ungültig ist
     */
    public function createSession(string $type, array $config = []): SessionInterface
    {
        if (!isset($this->sessionTypes[$type])) {
            throw new InvalidArgumentException(
                "Ungültiger Session-Typ: $type. Erlaubte Typen: " . implode(', ', array_keys($this->sessionTypes))
            );
        }

        $sessionClass = $this->sessionTypes[$type];

        $this->logger->debug("Erstelle Session vom Typ '$type'");

        return new $sessionClass($config, $this->logger);
    }

    /**
     * Registriert einen benutzerdefinierten Session-Typ
     *
     * @param string $type Session-Typ
     * @param string $class Session-Klasse
     * @return self
     */
    public function registerSessionType(string $type, string $class): self
    {
        if (!class_exists($class) || !is_subclass_of($class, SessionInterface::class)) {
            throw new InvalidArgumentException("Klasse $class muss SessionInterface implementieren");
        }

        $this->sessionTypes[$type] = $class;
        $this->logger->debug("Session-Typ '$type' registriert: $class");

        return $this;
    }
}