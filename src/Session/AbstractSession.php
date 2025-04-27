<?php


namespace Src\Session;

use Src\Log\LoggerInterface;
use Src\Log\NullLogger;

/**
 * Abstrakte Basis-Implementierung für Session-Handling
 *
 * Enthält gemeinsame Funktionalität für alle Session-Implementierungen
 */
abstract class AbstractSession implements SessionInterface
{
    /** @var string Namespace-Präfix für alle Session-Schlüssel */
    protected string $namespace = 'app';

    /** @var string Schlüssel für Flash-Messages */
    protected string $flashKey = '_flash';

    /** @var string Schlüssel für Security-Token */
    protected string $securityKey = '_security';

    /** @var bool Gibt an, ob die Session aktiv ist */
    protected bool $started = false;

    /** @var array Konfigurationsoptionen für das Session-Handling */
    protected array $config;

    /** @var LoggerInterface Logger für Session-Operationen */
    protected LoggerInterface $logger;

    /** @var int Letzter Zugriffszeitpunkt (Unix-Timestamp) */
    protected int $lastActivity = 0;

    /** @var bool Gibt an, ob die aktuelle Anfrage die Session nur lesen soll */
    protected bool $readOnly = false;

    /**
     * Konstruktor
     *
     * @param array $config Konfigurationsoptionen für das Session-Handling
     * @param LoggerInterface|null $logger Optional: Logger für Session-Operationen
     */
    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->logger = $logger ?? new NullLogger();

        if (isset($this->config['namespace'])) {
            $this->namespace = $this->config['namespace'];
        }

        // Wenn Session-Autostart aktiviert ist, Session starten
        if ($this->config['autostart'] ?? false) {
            $this->start();
        }
    }

    /**
     * Gibt die Standard-Konfiguration für das Session-Handling zurück
     *
     * @return array Standardkonfiguration
     */
    protected function getDefaultConfig(): array
    {
        return [
            'name' => 'PHPSESSID',           // Name des Session-Cookies
            'lifetime' => 0,                // Lebensdauer des Cookies in Sekunden (0 = bis Browser schließt)
            'path' => '/',                  // Pfad für den Cookie
            'domain' => null,               // Domain für den Cookie
            'secure' => false,              // Nur über HTTPS senden
            'httponly' => true,             // Nur über HTTP zugänglich, nicht über JavaScript
            'samesite' => 'Lax',            // SameSite-Richtlinie (Strict, Lax, None)
            'autostart' => false,           // Session automatisch starten
            'gc_maxlifetime' => 1440,       // Maximale Lebensdauer der Session-Daten in Sekunden
            'gc_probability' => 1,          // Wahrscheinlichkeit für Garbage Collection (zusammen mit gc_divisor)
            'gc_divisor' => 100,            // Teiler für Garbage-Collection-Wahrscheinlichkeit
            'lazy_write' => true,           // Nur schreiben, wenn sich Daten geändert haben
            'sid_length' => 48,             // Länge der Session-ID in Bytes (32-256)
            'sid_bits_per_character' => 6,  // Bits pro Zeichen (4: 0-9a-f, 5: 0-9a-v, 6: 0-9a-zA-Z,-)
            'strict_mode' => true,          // Strikte Überprüfung der Session-ID
            'use_fingerprint' => true,      // Browser-Fingerprint verwenden
            'inactivity_timeout' => 1800,   // Timeout bei Inaktivität in Sekunden (30 Minuten)
            'absolute_timeout' => 43200,    // Absolute Session-Lebensdauer (12 Stunden)
            'regenerate_after' => 300,      // Session-ID nach X Sekunden regenerieren (5 Minuten)
            'read_only' => false            // Session im Lesemodus starten (keine Schreibvorgänge)
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        // Session-Cookie-Parameter setzen
        $this->setCookieParams(
            $this->config['lifetime'],
            $this->config['path'],
            $this->config['domain'],
            $this->config['secure'],
            $this->config['httponly']
        );

        // SameSite-Richtlinie setzen
        $this->setSameSite($this->config['samesite']);

        // Session-Name setzen
        session_name($this->config['name']);

        // Session-Cache-Limiter und andere Parameter setzen
        session_cache_limiter('');
        ini_set('session.use_strict_mode', $this->config['strict_mode'] ? '1' : '0');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string)$this->config['gc_maxlifetime']);
        ini_set('session.gc_probability', (string)$this->config['gc_probability']);
        ini_set('session.gc_divisor', (string)$this->config['gc_divisor']);
        ini_set('session.lazy_write', $this->config['lazy_write'] ? '1' : '0');
        ini_set('session.sid_length', (string)$this->config['sid_length']);
        ini_set('session.sid_bits_per_character', (string)$this->config['sid_bits_per_character']);

        // Lese-Only-Modus setzen
        $this->readOnly = $this->config['read_only'] ?? false;

        // Session starten
        $result = $this->startSession();

        if ($result) {
            $this->started = true;

            // Initialisierung des Session-Arrays
            if (!isset($_SESSION[$this->namespace])) {
                $_SESSION[$this->namespace] = [];
            }

            // Flash-Messages initialisieren
            if (!isset($_SESSION[$this->namespace][$this->flashKey])) {
                $_SESSION[$this->namespace][$this->flashKey] = [
                    'new' => [],
                    'old' => []
                ];
            }

            // Letzte Aktivität setzen/prüfen
            $this->checkActivity();

            // Fingerprint prüfen
            $this->checkFingerprint();

            // Age der Session prüfen (absolute Lebensdauer)
            $this->checkAge();

            // Alten Flash-Data verarbeiten (von new nach old verschieben)
            $this->ageFlashData();

            $this->logger->debug('Session gestartet', [
                'id' => $this->getId(),
                'name' => session_name()
            ]);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function setCookieParams(
        int     $lifetime,
        ?string $path = null,
        ?string $domain = null,
        bool    $secure = false,
        bool    $httpOnly = false
    ): bool
    {
        $params = session_get_cookie_params();

        // Nur übergebene Parameter überschreiben
        if ($path === null) {
            $path = $params['path'];
        }

        if ($domain === null) {
            $domain = $params['domain'];
        }

        $samesite = $params['samesite'] ?? null;

        // Ab PHP 7.3 kann SameSite über session_set_cookie_params gesetzt werden
        if (PHP_VERSION_ID >= 70300) {
            return session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httpOnly,
                'samesite' => $samesite
            ]);
        }

        // Ältere PHP-Versionen
        return session_set_cookie_params($lifetime, $path, $domain, $secure, $httpOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function setSameSite(string $sameSite): bool
    {
        // Validieren
        $validValues = ['Strict', 'Lax', 'None'];
        if (!in_array($sameSite, $validValues, true)) {
            return false;
        }

        // Ab PHP 7.3 können wir SameSite über session_set_cookie_params setzen
        if (PHP_VERSION_ID >= 70300) {
            $params = session_get_cookie_params();
            return session_set_cookie_params([
                'lifetime' => $params['lifetime'],
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $sameSite
            ]);
        }

        // Für PHP < 7.3 müssen wir es über ini_set setzen
        return ini_set('session.cookie_samesite', $sameSite) !== false;
    }

    /**
     * Startet die Session mit der nativen PHP-Funktionalität
     *
     * Kann von abgeleiteten Klassen überschrieben werden, um alternative Session-Handler zu implementieren
     *
     * @return bool True bei Erfolg
     */
    protected function startSession(): bool
    {
        return session_start();
    }

    /**
     * Prüft die letzte Aktivität und regeneriert die Session, wenn nötig
     *
     * @return void
     */
    protected function checkActivity(): void
    {
        $now = time();
        $securityData = $this->getSecurityData();

        // Letzte Aktivität abrufen
        $lastActivity = $securityData['last_activity'] ?? 0;
        $this->lastActivity = $lastActivity;

        // Prüfen auf Inaktivitäts-Timeout
        $inactivityTimeout = $this->config['inactivity_timeout'];
        if ($inactivityTimeout > 0 && $lastActivity > 0 && ($now - $lastActivity) > $inactivityTimeout) {
            $this->logger->notice('Session wegen Inaktivität beendet', [
                'id' => $this->getId(),
                'inactivity' => $now - $lastActivity,
                'timeout' => $inactivityTimeout
            ]);

            $this->destroy();
            return;
        }

        // Prüfen, ob Session-ID regeneriert werden muss
        $regenerateAfter = $this->config['regenerate_after'];
        $lastRegenerated = $securityData['last_regenerated'] ?? 0;

        if ($regenerateAfter > 0 && $lastRegenerated > 0 && ($now - $lastRegenerated) > $regenerateAfter) {
            $this->regenerateId();
        }

        // Letzte Aktivität aktualisieren
        $securityData['last_activity'] = $now;
        $this->setSecurityData($securityData);
    }

    /**
     * Holt die Security-Daten aus der Session
     *
     * @return array Security-Daten
     */
    protected function getSecurityData(): array
    {
        if (!isset($_SESSION[$this->namespace][$this->securityKey])) {
            // Initiale Security-Daten erstellen
            return [
                'created_at' => time(),
                'last_activity' => time(),
                'last_regenerated' => time(),
                'fingerprint' => null
            ];
        }

        return $_SESSION[$this->namespace][$this->securityKey];
    }

    /**
     * {@inheritDoc}
     */
    public function getId(): ?string
    {
        if (!$this->started) {
            return null;
        }

        return session_id();
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(): bool
    {
        if (!$this->started) {
            return true;
        }

        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            $this->logger->debug('Session-Destroy im Read-Only-Modus übersprungen');
            return false;
        }

        // Session-Cookie löschen
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? null
            ]
        );

        // Session-Daten löschen
        $_SESSION = [];

        // Session beenden
        $result = session_destroy();
        $this->started = false;

        $this->logger->debug('Session zerstört');

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function regenerateId(bool $deleteOldSession = true): bool
    {
        if (!$this->started) {
            $this->start();
        }

        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            $this->logger->debug('Regenerate-ID im Read-Only-Modus übersprungen');
            return false;
        }

        // Session-ID regenerieren
        $result = session_regenerate_id($deleteOldSession);

        if ($result) {
            // Zeitpunkt der Regenerierung aktualisieren
            $securityData = $this->getSecurityData();
            $securityData['last_regenerated'] = time();
            $this->setSecurityData($securityData);

            $this->logger->debug('Session-ID regeneriert', [
                'id' => $this->getId(),
                'delete_old' => $deleteOldSession
            ]);
        }

        return $result;
    }

    /**
     * Setzt die Security-Daten in der Session
     *
     * @param array $data Security-Daten
     * @return void
     */
    protected function setSecurityData(array $data): void
    {
        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            return;
        }

        $_SESSION[$this->namespace][$this->securityKey] = $data;
    }

    /**
     * Prüft den Browser-Fingerprint
     *
     * @return void
     */
    protected function checkFingerprint(): void
    {
        if (!$this->config['use_fingerprint']) {
            return;
        }

        $securityData = $this->getSecurityData();

        // Aktuellen Fingerprint erstellen
        $currentFingerprint = $this->generateFingerprint();

        // Gespeicherten Fingerprint prüfen
        $storedFingerprint = $securityData['fingerprint'] ?? null;

        if ($storedFingerprint !== null && $storedFingerprint !== $currentFingerprint) {
            $this->logger->warning('Session-Fingerprint ungültig', [
                'id' => $this->getId(),
                'stored' => $storedFingerprint,
                'current' => $currentFingerprint
            ]);

            // Session bei ungültigem Fingerprint beenden
            $this->destroy();
            return;
        }

        // Fingerprint speichern (falls noch nicht gesetzt)
        if ($storedFingerprint === null) {
            $securityData['fingerprint'] = $currentFingerprint;
            $this->setSecurityData($securityData);
        }
    }

    /**
     * Generiert einen Browser-Fingerprint basierend auf Client-Informationen
     *
     * @return string Generierter Fingerprint
     */
    protected function generateFingerprint(): string
    {
        $data = [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'ip' => $this->getClientIp(),
            // Optional: weitere Identifikationsmerkmale hinzufügen
        ];

        // Hash erstellen (nicht zu spezifisch, damit kleinere Änderungen toleriert werden)
        return hash('xxh3', json_encode($data));
    }

    /**
     * Ermittelt die IP-Adresse des Clients
     *
     * @return string IP-Adresse des Clients
     */
    protected function getClientIp(): string
    {
        $keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Standard-Proxy/Load-Balancer
            'HTTP_X_REAL_IP',        // Nginx
            'HTTP_CLIENT_IP',        // Client-IP
            'REMOTE_ADDR'            // Direkte Verbindung
        ];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                // Bei mehreren IPs (z.B. X-Forwarded-For) die erste verwenden
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /**
     * Prüft das Alter der Session (absolute Lebensdauer)
     *
     * @return void
     */
    protected function checkAge(): void
    {
        $absoluteTimeout = $this->config['absolute_timeout'];

        if ($absoluteTimeout <= 0) {
            return;
        }

        $securityData = $this->getSecurityData();
        $createdAt = $securityData['created_at'] ?? time();

        // Prüfen, ob Session zu alt ist
        if ((time() - $createdAt) > $absoluteTimeout) {
            $this->logger->notice('Session wegen Überschreitung der maximalen Lebensdauer beendet', [
                'id' => $this->getId(),
                'age' => time() - $createdAt,
                'timeout' => $absoluteTimeout
            ]);

            $this->destroy();
        }
    }

    /**
     * Verarbeitet alte Flash-Daten
     *
     * Verschiebt neue Flash-Daten in alte Flash-Daten und löscht alte Flash-Daten
     *
     * @return void
     */
    protected function ageFlashData(): void
    {
        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            return;
        }

        $_SESSION[$this->namespace][$this->flashKey]['old'] =
            $_SESSION[$this->namespace][$this->flashKey]['new'];

        $_SESSION[$this->namespace][$this->flashKey]['new'] = [];
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        if (!$this->started) {
            $this->start();
        }

        return isset($_SESSION[$this->namespace][$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->started) {
            $this->start();
        }

        return $_SESSION[$this->namespace][$key] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value): self
    {
        if (!$this->started) {
            $this->start();
        }

        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            $this->logger->debug("Session-Set für '{$key}' im Read-Only-Modus übersprungen");
            return $this;
        }

        $_SESSION[$this->namespace][$key] = $value;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): self
    {
        if (!$this->started) {
            $this->start();
        }

        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            $this->logger->debug("Session-Remove für '{$key}' im Read-Only-Modus übersprungen");
            return $this;
        }

        unset($_SESSION[$this->namespace][$key]);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function all(): array
    {
        if (!$this->started) {
            $this->start();
        }

        return $_SESSION[$this->namespace] ?? [];
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): self
    {
        if (!$this->started) {
            $this->start();
        }

        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            $this->logger->debug("Session-Clear im Read-Only-Modus übersprungen");
            return $this;
        }

        // Wichtige Session-Daten sichern
        $securityData = $this->getSecurityData();
        $flashData = $_SESSION[$this->namespace][$this->flashKey] ?? null;

        // Session-Daten löschen
        $_SESSION[$this->namespace] = [];

        // Wichtige Daten wiederherstellen
        $this->setSecurityData($securityData);

        if ($flashData !== null) {
            $_SESSION[$this->namespace][$this->flashKey] = $flashData;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function flash(string $key, mixed $value): self
    {
        if (!$this->started) {
            $this->start();
        }

        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            $this->logger->debug("Session-Flash für '{$key}' im Read-Only-Modus übersprungen");
            return $this;
        }

        $_SESSION[$this->namespace][$this->flashKey]['new'][$key] = $value;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function reflash(string $key): self
    {
        if (!$this->started) {
            $this->start();
        }

        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            $this->logger->debug("Session-Reflash für '{$key}' im Read-Only-Modus übersprungen");
            return $this;
        }

        if (isset($_SESSION[$this->namespace][$this->flashKey]['old'][$key])) {
            $_SESSION[$this->namespace][$this->flashKey]['new'][$key] =
                $_SESSION[$this->namespace][$this->flashKey]['old'][$key];
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function reflashAll(): self
    {
        if (!$this->started) {
            $this->start();
        }

        // Im Read-Only-Modus keine Änderungen vornehmen
        if ($this->readOnly) {
            $this->logger->debug("Session-ReflashAll im Read-Only-Modus übersprungen");
            return $this;
        }

        $_SESSION[$this->namespace][$this->flashKey]['new'] = array_merge(
            $_SESSION[$this->namespace][$this->flashKey]['new'],
            $_SESSION[$this->namespace][$this->flashKey]['old']
        );

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isActive(): bool
    {
        return $this->started && session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * {@inheritDoc}
     */
    public function sendCookies(): bool
    {
        if (!$this->started) {
            return false;
        }

        // Im Read-Only-Modus keine Cookies senden
        if ($this->readOnly) {
            return false;
        }

        // In PHP wird der Session-Cookie automatisch gesendet
        return true;
    }

    /**
     * Gibt einen Flash-Wert zurück und entfernt ihn aus der Session
     *
     * @param string $key Schlüssel
     * @param mixed $default Standardwert, falls Schlüssel nicht existiert
     * @return mixed Wert oder Standardwert
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        if (!$this->started) {
            $this->start();
        }

        // Zuerst in alten Flash-Daten suchen
        if (isset($_SESSION[$this->namespace][$this->flashKey]['old'][$key])) {
            $value = $_SESSION[$this->namespace][$this->flashKey]['old'][$key];

            // Im Read-Only-Modus Flash-Daten nicht entfernen
            if (!$this->readOnly) {
                unset($_SESSION[$this->namespace][$this->flashKey]['old'][$key]);
            }

            return $value;
        }

        return $default;
    }

    /**
     * Prüft, ob ein Flash-Wert vorhanden ist
     *
     * @param string $key Schlüssel
     * @return bool True, wenn der Flash-Wert existiert
     */
    public function hasFlash(string $key): bool
    {
        if (!$this->started) {
            $this->start();
        }

        return isset($_SESSION[$this->namespace][$this->flashKey]['old'][$key]);
    }

    /**
     * Gibt alle Flash-Werte zurück
     *
     * @return array Alle aktuellen Flash-Werte
     */
    public function getFlashes(): array
    {
        if (!$this->started) {
            $this->start();
        }

        return $_SESSION[$this->namespace][$this->flashKey]['old'] ?? [];
    }

    /**
     * Gibt den aktuellen Namespace zurück
     *
     * @return string
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Ändert den Namespace für die Session
     *
     * @param string $namespace Neuer Namespace
     * @return self
     */
    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;

        // Session initialisieren, falls noch nicht geschehen
        if ($this->started && !isset($_SESSION[$this->namespace])) {
            $_SESSION[$this->namespace] = [];
        }

        return $this;
    }

    /**
     * Gibt zurück, ob die Session im Read-Only-Modus ist
     *
     * @return bool
     */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Setzt den Read-Only-Modus für die Session
     *
     * @param bool $readOnly True, um den Read-Only-Modus zu aktivieren
     * @return self
     */
    public function setReadOnly(bool $readOnly): self
    {
        $this->readOnly = $readOnly;
        return $this;
    }
}