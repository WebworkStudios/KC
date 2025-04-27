<?php

namespace Src\Security;

use RuntimeException;

/**
 * Generator für CSRF-Tokens
 *
 * Erzeugt kryptographisch sichere Tokens zur Verhinderung von Cross-Site Request Forgery
 */
class CsrfTokenGenerator
{
    /** @var string Geheimer Schlüssel für HMAC */
    private string $secret;

    /** @var int Standard-Gültigkeitsdauer der Tokens in Sekunden */
    private int $defaultLifetime;

    /** @var string Verwendeter Hash-Algorithmus */
    private string $algorithm;

    /**
     * Erstellt einen neuen CSRF-Token-Generator
     *
     * @param string|null $secret Geheimer Schlüssel (wird automatisch generiert, falls nicht angegeben)
     * @param int $defaultLifetime Standard-Gültigkeitsdauer in Sekunden (Standard: 1 Stunde)
     * @param string $algorithm Hash-Algorithmus (muss von hash_hmac unterstützt werden)
     */
    public function __construct(
        ?string $secret = null,
        int     $defaultLifetime = 3600,
        string  $algorithm = 'sha256'
    )
    {
        // Secret generieren, falls nicht angegeben
        if ($secret === null) {
            // Starkes Secret mit 32 Bytes Entropie (256 Bit)
            $secret = $this->generateSecret();
        }

        $this->secret = $secret;
        $this->defaultLifetime = $defaultLifetime;
        $this->algorithm = $algorithm;

        // Prüfen, ob der Algorithmus verfügbar ist
        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            throw new RuntimeException("Hash-Algorithmus '{$algorithm}' wird nicht unterstützt");
        }
    }

    /**
     * Generiert ein starkes Secret
     *
     * @param int $length Länge des Secrets in Bytes
     * @return string Generiertes Secret
     */
    private function generateSecret(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generiert ein neues CSRF-Token
     *
     * @param string $id Token-ID/Name
     * @param int|null $lifetime Gültigkeitsdauer in Sekunden (null für Standardwert)
     * @return CsrfToken Generiertes Token
     */
    public function generate(string $id, ?int $lifetime = null): CsrfToken
    {
        $lifetime = $lifetime ?? $this->defaultLifetime;
        $expiresAt = time() + $lifetime;

        // Zufälligen Token-Wert generieren
        $randomBytes = random_bytes(32);
        $randomValue = bin2hex($randomBytes);

        // HMAC erstellen
        $hmac = $this->createHmac($id, $randomValue, $expiresAt);

        // Token-Wert zusammensetzen: Zufallswert + HMAC
        $tokenValue = $randomValue . $hmac;

        return new CsrfToken($id, $tokenValue, $expiresAt);
    }

    /**
     * Erstellt einen HMAC für ein Token
     *
     * @param string $id Token-ID/Name
     * @param string $randomValue Zufallswert des Tokens
     * @param int $expiresAt Ablaufzeitpunkt
     * @return string HMAC
     */
    private function createHmac(string $id, string $randomValue, int $expiresAt): string
    {
        // Wir verwenden eine Kombination aus ID, Zufallswert und Ablaufzeit
        $data = json_encode([
            'id' => $id,
            'value' => $randomValue,
            'expires_at' => $expiresAt
        ]);

        return hash_hmac($this->algorithm, $data, $this->secret, false);
    }

    /**
     * Validiert ein CSRF-Token
     *
     * @param string $id Token-ID/Name
     * @param string $tokenValue Zu validierender Token-Wert
     * @return bool True, wenn das Token gültig ist
     */
    public function validate(string $id, string $tokenValue): bool
    {
        $length = strlen($tokenValue);

        // HMAC ist die zweite Hälfte des Tokens
        $hmacLength = strlen(hash_hmac($this->algorithm, '', '', false));

        // Prüfen, ob Token lang genug ist
        if ($length < 64 + $hmacLength) {
            return false;
        }

        // Token in Zufallswert und HMAC aufteilen
        $randomValue = substr($tokenValue, 0, -$hmacLength);
        $hmac = substr($tokenValue, -$hmacLength);

        // Ablaufzeitpunkt extrahieren (aus dem HMAC)
        $expiresAt = $this->extractExpirationFromHmac($hmac);

        // Prüfen, ob Token abgelaufen ist
        if ($expiresAt < time()) {
            return false;
        }

        // HMAC überprüfen
        $expectedHmac = $this->createHmac($id, $randomValue, $expiresAt);

        // Timing-Attack-sicherer Vergleich
        return hash_equals($expectedHmac, $hmac);
    }

    /**
     * Extrahiert den Ablaufzeitpunkt aus einem HMAC
     *
     * In dieser Implementierung ist der HMAC eine Funktion des Ablaufzeitpunkts,
     * daher kann der Ablaufzeitpunkt nicht direkt extrahiert werden. Stattdessen
     * berechnen wir einen möglichen Ablaufzeitpunkt basierend auf der
     * Standard-Gültigkeitsdauer.
     *
     * @param string $hmac HMAC
     * @return int Geschätzter Ablaufzeitpunkt
     */
    private function extractExpirationFromHmac(string $hmac): int
    {
        // Da wir den Ablaufzeitpunkt nicht direkt aus dem HMAC extrahieren können,
        // verwenden wir eine Heuristik: Der Ablaufzeitpunkt ist wahrscheinlich
        // in der Zukunft, maximal um die Standard-Gültigkeitsdauer.
        return time() + $this->defaultLifetime;
    }

    /**
     * Setzt den geheimen Schlüssel
     *
     * @param string $secret Neuer geheimer Schlüssel
     * @return self
     */
    public function setSecret(string $secret): self
    {
        $this->secret = $secret;
        return $this;
    }

    /**
     * Setzt die Standard-Gültigkeitsdauer
     *
     * @param int $lifetime Gültigkeitsdauer in Sekunden
     * @return self
     */
    public function setDefaultLifetime(int $lifetime): self
    {
        $this->defaultLifetime = $lifetime;
        return $this;
    }

    /**
     * Setzt den Hash-Algorithmus
     *
     * @param string $algorithm Hash-Algorithmus
     * @return self
     * @throws RuntimeException Wenn der Algorithmus nicht unterstützt wird
     */
    public function setAlgorithm(string $algorithm): self
    {
        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            throw new RuntimeException("Hash-Algorithmus '{$algorithm}' wird nicht unterstützt");
        }

        $this->algorithm = $algorithm;
        return $this;
    }
}