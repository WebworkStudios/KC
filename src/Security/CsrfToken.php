<?php


namespace Src\Security;

/**
 * Repräsentiert ein CSRF-Token
 *
 * Enthält den Token-Wert und dessen Ablaufzeit
 */
class CsrfToken
{
    /**
     * Erstellt ein neues CSRF-Token
     *
     * @param string $id Token-ID/Name
     * @param string $value Token-Wert
     * @param int $expiresAt Ablaufzeitpunkt (Unix-Timestamp)
     */
    public function __construct(
        private readonly string $id,
        private readonly string $value,
        private readonly int    $expiresAt
    )
    {
    }

    /**
     * Gibt die Token-ID zurück
     *
     * @return string Token-ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Gibt den Token-Wert zurück
     *
     * @return string Token-Wert
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Gibt den Ablaufzeitpunkt zurück
     *
     * @return int Ablaufzeitpunkt als Unix-Timestamp
     */
    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * Prüft, ob das Token abgelaufen ist
     *
     * @return bool True, wenn das Token abgelaufen ist
     */
    public function isExpired(): bool
    {
        return time() > $this->expiresAt;
    }

    /**
     * Gibt die verbleibende Gültigkeitsdauer zurück
     *
     * @return int Verbleibende Sekunden (0 wenn abgelaufen)
     */
    public function getTimeRemaining(): int
    {
        $remaining = $this->expiresAt - time();
        return max(0, $remaining);
    }

    /**
     * Erstellt ein Key-Value-Array zur einfachen Serialisierung
     *
     * @return array Token-Daten
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'value' => $this->value,
            'expires_at' => $this->expiresAt
        ];
    }

    /**
     * Gibt den Token-Wert als String zurück
     *
     * @return string Token-Wert
     */
    public function __toString(): string
    {
        return $this->value;
    }
}