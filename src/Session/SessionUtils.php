<?php


namespace Src\Session;

use Redis;
use Throwable;

/**
 * Hilfsklasse für das Session-Management
 *
 * Bietet Hilfsfunktionen zum Umgang mit Sessions
 */
class SessionUtils
{
    /**
     * Bestimmt, ob eine Rotation (Regenerierung) der Session-ID erforderlich ist
     *
     * @param int $lastRotation Zeitpunkt der letzten Rotation (Unix-Timestamp)
     * @param int $rotateFrequency Häufigkeit der Rotation in Sekunden
     * @return bool True, wenn eine Rotation erforderlich ist
     */
    public static function shouldRotateSession(int $lastRotation, int $rotateFrequency): bool
    {
        return (time() - $lastRotation) > $rotateFrequency;
    }

    /**
     * Prüft auf Anzeichen von Session-Fixation-Angriffen
     *
     * @param SessionInterface $session Session-Instanz
     * @param string $clientIp Client-IP-Adresse
     * @param string $userAgent Client-User-Agent
     * @return bool True, wenn Anzeichen für einen Angriff gefunden wurden
     */
    public static function detectSessionFixation(
        SessionInterface $session,
        string           $clientIp,
        string           $userAgent
    ): bool
    {
        // Prüfen, ob Session-Informationen existieren
        if (!$session->has('_security')) {
            return false;
        }

        $security = $session->get('_security');

        // Bekannte IP und User-Agent prüfen, falls gespeichert
        if (isset($security['ip']) && isset($security['user_agent'])) {
            $storedIp = $security['ip'];
            $storedUserAgent = $security['user_agent'];

            // IP-Wechsel bei authentifizierten Sessions als verdächtig ansehen
            if ($session->has('user_id') && $storedIp !== $clientIp) {
                return true;
            }

            // User-Agent-Wechsel bei authentifizierten Sessions als verdächtig ansehen
            if ($session->has('user_id') && $storedUserAgent !== $userAgent) {
                return true;
            }
        }

        return false;
    }

    /**
     * Setzt Client-Sicherheitsinformationen in der Session
     *
     * @param SessionInterface $session Session-Instanz
     * @param string $ip Client-IP-Adresse
     * @param string $userAgent Client-User-Agent
     * @param string $acceptLanguage Accept-Language-Header
     * @return void
     */
    public static function setClientSecurityInfo(
        SessionInterface $session,
        string           $ip,
        string           $userAgent,
        string           $acceptLanguage = ''
    ): void
    {
        $security = $session->get('_security', []);

        // Client-Informationen aktualisieren
        $security['ip'] = $ip;
        $security['user_agent'] = $userAgent;
        $security['accept_language'] = $acceptLanguage;
        $security['fingerprint'] = self::generateFingerprint($ip, $userAgent, $acceptLanguage);
        $security['last_seen'] = time();

        // In der Session speichern
        $session->set('_security', $security);
    }

    /**
     * Generiert einen Browser-Fingerprint
     *
     * @param string $ip IP-Adresse
     * @param string $userAgent User-Agent-String
     * @param string $acceptLanguage Accept-Language-Header
     * @param string $salt Zusätzlicher Salt für den Fingerprint
     * @return string Generierter Fingerprint
     */
    public static function generateFingerprint(
        string $ip,
        string $userAgent,
        string $acceptLanguage = '',
        string $salt = ''
    ): string
    {
        // Daten für den Fingerprint sammeln
        $data = [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'accept_language' => $acceptLanguage,
            'salt' => $salt,
        ];

        // Daten serialisieren und hashen
        $serialized = json_encode($data);
        return hash('xxh3', $serialized);
    }

    /**
     * Löscht alle abgelaufenen Sessions aus dem Speicher
     *
     * @param string $sessionDir Verzeichnis mit den Session-Dateien
     * @param int $maxLifetime Maximale Lebensdauer in Sekunden
     * @return int Anzahl der gelöschten Sessions
     */
    public static function cleanUpExpiredSessions(string $sessionDir, int $maxLifetime): int
    {
        $count = 0;
        $now = time();

        if (!is_dir($sessionDir) || !is_readable($sessionDir)) {
            return 0;
        }

        // Session-Dateien durchgehen
        $files = scandir($sessionDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $sessionDir . DIRECTORY_SEPARATOR . $file;

            // Nur Dateien verarbeiten
            if (is_file($path) && strpos($file, 'sess_') === 0) {
                $lastModified = filemtime($path);

                // Prüfen, ob Session abgelaufen ist
                if ($lastModified + $maxLifetime < $now) {
                    // Session-Datei löschen
                    if (unlink($path)) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Prüft, ob eine Session aktiv ist und ein gültiger Benutzer angemeldet ist
     *
     * @param SessionInterface $session Session-Instanz
     * @return bool True, wenn ein Benutzer angemeldet ist
     */
    public static function isAuthenticated(SessionInterface $session): bool
    {
        if (!$session->isActive()) {
            return false;
        }

        // Prüfen, ob ein Benutzer angemeldet ist
        if (!$session->has('user_id')) {
            return false;
        }

        // Prüfen, ob Anmeldezeitpunkt gespeichert ist
        if (!$session->has('logged_in_at')) {
            return false;
        }

        // Hier könnten weitere Validierungen stattfinden

        return true;
    }

    /**
     * Führt Garbage Collection für Redis-Sessions durch
     *
     * @param Redis $redis Redis-Verbindung
     * @param string $prefix Präfix für Session-Schlüssel
     * @param int $probability Wahrscheinlichkeit für GC (0-100)
     * @return int Anzahl der gelöschten Sessions
     */
    public static function redisGarbageCollection(Redis $redis, string $prefix, int $probability = 10): int
    {
        // Nur in einem bestimmten Prozentsatz der Fälle ausführen
        if (random_int(1, 100) > $probability) {
            return 0;
        }

        try {
            // Alle Session-Schlüssel mit SCAN suchen
            $iterator = null;
            $deleted = 0;
            $pattern = $prefix . '*';

            do {
                $keys = $redis->scan($iterator, $pattern, 100);

                if ($keys) {
                    // Lock-Keys finden und löschen (könnten übrig geblieben sein)
                    $lockKeys = array_filter($keys, function ($key) {
                        return strpos($key, ':lock') !== false;
                    });

                    if (!empty($lockKeys)) {
                        $deleted += $redis->del($lockKeys);
                    }
                }
            } while ($iterator > 0);

            return $deleted;
        } catch (Throwable $e) {
            // Fehler ignorieren
            return 0;
        }
    }
}