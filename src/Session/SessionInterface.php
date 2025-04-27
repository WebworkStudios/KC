<?php


namespace Src\Session;

/**
 * Interface für Session-Management
 *
 * Definiert die grundlegenden Methoden für das Session-Handling
 */
interface SessionInterface
{
    /**
     * Startet die Session, falls noch nicht geschehen
     *
     * @return bool True bei Erfolg, false bei Fehler
     */
    public function start(): bool;

    /**
     * Beendet die aktuelle Session und löscht alle Session-Daten
     *
     * @return bool True bei Erfolg
     */
    public function destroy(): bool;

    /**
     * Regeneriert die Session-ID
     *
     * @param bool $deleteOldSession True, um die alte Session zu löschen
     * @return bool True bei Erfolg
     */
    public function regenerateId(bool $deleteOldSession = true): bool;

    /**
     * Prüft, ob ein Wert in der Session existiert
     *
     * @param string $key Schlüssel
     * @return bool True, wenn der Schlüssel existiert
     */
    public function has(string $key): bool;

    /**
     * Holt einen Wert aus der Session
     *
     * @param string $key Schlüssel
     * @param mixed $default Standardwert, falls Schlüssel nicht existiert
     * @return mixed Wert aus der Session oder Standardwert
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Setzt einen Wert in der Session
     *
     * @param string $key Schlüssel
     * @param mixed $value Wert
     * @return self
     */
    public function set(string $key, mixed $value): self;

    /**
     * Löscht einen Wert aus der Session
     *
     * @param string $key Schlüssel
     * @return self
     */
    public function remove(string $key): self;

    /**
     * Holt alle Werte aus der Session
     *
     * @return array Alle Session-Werte
     */
    public function all(): array;

    /**
     * Löscht alle Werte aus der Session
     *
     * @return self
     */
    public function clear(): self;

    /**
     * Speichert einen Flash-Message-Wert für die nächste Anfrage
     *
     * @param string $key Schlüssel
     * @param mixed $value Wert
     * @return self
     */
    public function flash(string $key, mixed $value): self;

    /**
     * Behält einen Flash-Message-Wert für eine weitere Anfrage bei
     *
     * @param string $key Schlüssel der beizubehaltenden Flash-Message
     * @return self
     */
    public function reflash(string $key): self;

    /**
     * Behält alle Flash-Message-Werte für eine weitere Anfrage bei
     *
     * @return self
     */
    public function reflashAll(): self;

    /**
     * Sendet die aktuellen Session-Cookies an den Client
     *
     * @return bool True bei Erfolg
     */
    public function sendCookies(): bool;

    /**
     * Gibt die aktuelle Session-ID zurück
     *
     * @return string|null Session-ID oder null, wenn keine Session aktiv ist
     */
    public function getId(): ?string;

    /**
     * Prüft, ob die Session aktiv ist
     *
     * @return bool True, wenn die Session aktiv ist
     */
    public function isActive(): bool;

    /**
     * Setzt den Wert des Session-Cookie-Parameters
     *
     * @param int $lifetime Lebensdauer des Cookies in Sekunden
     * @param string|null $path Pfad, auf dem der Cookie verfügbar sein soll
     * @param string|null $domain Domain, auf der der Cookie verfügbar sein soll
     * @param bool $secure True, wenn der Cookie nur über HTTPS gesendet werden soll
     * @param bool $httpOnly True, wenn der Cookie nur über HTTP und nicht über JavaScript zugänglich sein soll
     * @return bool True bei Erfolg
     */
    public function setCookieParams(
        int     $lifetime,
        ?string $path = null,
        ?string $domain = null,
        bool    $secure = false,
        bool    $httpOnly = false
    ): bool;

    /**
     * Setzt die SameSite-Richtlinie für den Session-Cookie
     *
     * @param string $sameSite 'Strict', 'Lax' oder 'None'
     * @return bool True bei Erfolg
     */
    public function setSameSite(string $sameSite): bool;
}