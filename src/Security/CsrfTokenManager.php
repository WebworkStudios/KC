<?php

namespace Src\Security;

use Src\Log\LoggerInterface;
use Src\Log\NullLogger;
use Src\Session\SessionInterface;

/**
 * Manager für CSRF-Tokens
 *
 * Verwaltet die Erstellung, Validierung und Speicherung von CSRF-Tokens
 */
class CsrfTokenManager
{
    /** @var string Schlüssel zum Speichern der Tokens in der Session */
    private string $sessionKey = '_csrf_tokens';

    /** @var int Maximale Anzahl gleichzeitig gespeicherter Tokens */
    private int $maxTokens;

    /** @var CsrfTokenGenerator Token-Generator */
    private CsrfTokenGenerator $generator;

    /** @var SessionInterface Session-Instanz */
    private SessionInterface $session;

    /** @var LoggerInterface Logger für CSRF-Operationen */
    private LoggerInterface $logger;

    /**
     * Erstellt einen neuen CSRF-Token-Manager
     *
     * @param SessionInterface $session Session-Instanz
     * @param CsrfTokenGenerator|null $generator Optional: Token-Generator
     * @param LoggerInterface|null $logger Optional: Logger für CSRF-Operationen
     * @param int $maxTokens Maximale Anzahl gleichzeitig gespeicherter Tokens
     */
    public function __construct(
        SessionInterface    $session,
        ?CsrfTokenGenerator $generator = null,
        ?LoggerInterface    $logger = null,
        int                 $maxTokens = 100
    )
    {
        $this->session = $session;
        $this->generator = $generator ?? new CsrfTokenGenerator();
        $this->logger = $logger ?? new NullLogger();
        $this->maxTokens = $maxTokens;
    }

    /**
     * Generiert ein neues CSRF-Token
     *
     * @param string $id Token-ID/Name
     * @param int|null $lifetime Optional: Gültigkeitsdauer in Sekunden
     * @return CsrfToken Generiertes Token
     */
    public function getToken(string $id, ?int $lifetime = null): CsrfToken
    {
        // Prüfen, ob bereits ein gültiges Token existiert
        $storedToken = $this->getStoredToken($id);

        if ($storedToken !== null && !$storedToken->isExpired()) {
            // Bestehendes Token wiederverwenden
            $this->logger->debug("Vorhandenes CSRF-Token für '{$id}' wiederverwendet", [
                'id' => $id,
                'time_remaining' => $storedToken->getTimeRemaining()
            ]);

            return $storedToken;
        }

        // Neues Token generieren
        $token = $this->generator->generate($id, $lifetime);
        $this->storeToken($token);

        $this->logger->debug("Neues CSRF-Token für '{$id}' generiert", [
            'id' => $id,
            'expires_at' => $token->getExpiresAt()
        ]);

        return $token;
    }

    /**
     * Holt ein gespeichertes Token aus der Session
     *
     * @param string $id Token-ID/Name
     * @return CsrfToken|null Token oder null, wenn nicht gefunden
     */
    private function getStoredToken(string $id): ?CsrfToken
    {
        $tokens = $this->getStoredTokens();

        if (!isset($tokens[$id])) {
            return null;
        }

        $tokenData = $tokens[$id];

        return new CsrfToken(
            $id,
            $tokenData['value'],
            $tokenData['expires_at']
        );
    }

    /**
     * Holt alle gespeicherten Tokens aus der Session
     *
     * @return array Gespeicherte Tokens
     */
    private function getStoredTokens(): array
    {
        return $this->session->get($this->sessionKey, []);
    }

    /**
     * Speichert ein Token in der Session
     *
     * @param CsrfToken $token Zu speicherndes Token
     * @return void
     */
    private function storeToken(CsrfToken $token): void
    {
        $tokens = $this->getStoredTokens();

        // Token hinzufügen/aktualisieren
        $tokens[$token->getId()] = [
            'value' => $token->getValue(),
            'expires_at' => $token->getExpiresAt()
        ];

        // Wenn zu viele Tokens, alte entfernen
        $this->limitTokenCount($tokens);

        // In Session speichern
        $this->session->set($this->sessionKey, $tokens);
    }

    /**
     * Begrenzt die Anzahl der gespeicherten Tokens
     *
     * Entfernt die ältesten Tokens, wenn die maximale Anzahl überschritten wird
     *
     * @param array &$tokens Token-Array (Referenz)
     * @return void
     */
    private function limitTokenCount(array &$tokens): void
    {
        if (count($tokens) <= $this->maxTokens) {
            return;
        }

        // Nach Ablaufzeit sortieren (älteste zuerst)
        uasort($tokens, function ($a, $b) {
            return $a['expires_at'] <=> $b['expires_at'];
        });

        // Überzählige Tokens entfernen
        $tokens = array_slice($tokens, count($tokens) - $this->maxTokens, null, true);
    }

    /**
     * Generiert ein einmaliges (nicht in der Session gespeichertes) CSRF-Token
     *
     * Dieses Token ist besonders sicher, da es nur einmal verwendet werden kann.
     *
     * @param string $id Token-ID/Name
     * @param int|null $lifetime Optional: Gültigkeitsdauer in Sekunden
     * @return CsrfToken Generiertes Token
     */
    public function getOneTimeToken(string $id, ?int $lifetime = null): CsrfToken
    {
        // Token generieren (nicht speichern)
        $token = $this->generator->generate($id, $lifetime);

        $this->logger->debug("Einmaliges CSRF-Token für '{$id}' generiert", [
            'id' => $id,
            'expires_at' => $token->getExpiresAt()
        ]);

        return $token;
    }

    /**
     * Überprüft ein Token und wirft eine Exception, wenn es ungültig ist
     *
     * @param string $id Token-ID/Name
     * @param string $value Token-Wert
     * @param bool $removeToken True, um das Token nach erfolgreicher Validierung zu entfernen
     * @return void
     * @throws CsrfTokenException Wenn das Token ungültig ist
     */
    public function validateTokenOrFail(string $id, string $value, bool $removeToken = true): void
    {
        if (!$this->validateToken($id, $value, $removeToken)) {
            throw new CsrfTokenException("Ungültiges CSRF-Token für '{$id}'");
        }
    }

    /**
     * Überprüft ein CSRF-Token
     *
     * @param string $id Token-ID/Name
     * @param string $value Token-Wert
     * @param bool $removeToken True, um das Token nach erfolgreicher Validierung zu entfernen
     * @return bool True, wenn das Token gültig ist
     */
    public function validateToken(string $id, string $value, bool $removeToken = true): bool
    {
        // Token vom Generator überprüfen
        $valid = $this->generator->validate($id, $value);

        // Wenn Token gültig ist und entfernt werden soll
        if ($valid && $removeToken) {
            $this->removeToken($id);

            $this->logger->debug("CSRF-Token '{$id}' validiert und entfernt");
        } elseif ($valid) {
            $this->logger->debug("CSRF-Token '{$id}' validiert");
        } else {
            $this->logger->notice("Ungültiges CSRF-Token '{$id}'");
        }

        return $valid;
    }

    /**
     * Entfernt ein Token aus der Session
     *
     * @param string $id Token-ID/Name
     * @return void
     */
    public function removeToken(string $id): void
    {
        $tokens = $this->getStoredTokens();

        if (isset($tokens[$id])) {
            unset($tokens[$id]);
            $this->session->set($this->sessionKey, $tokens);

            $this->logger->debug("CSRF-Token '{$id}' entfernt");
        }
    }

    /**
     * Bereinigt abgelaufene Tokens
     *
     * @return int Anzahl der bereinigten Tokens
     */
    public function cleanExpiredTokens(): int
    {
        $tokens = $this->getStoredTokens();
        $count = 0;
        $now = time();

        foreach ($tokens as $id => $tokenData) {
            if ($tokenData['expires_at'] <= $now) {
                unset($tokens[$id]);
                $count++;
            }
        }

        if ($count > 0) {
            $this->session->set($this->sessionKey, $tokens);
            $this->logger->debug("{$count} abgelaufene CSRF-Tokens bereinigt");
        }

        return $count;
    }

    /**
     * Setzt den Session-Schlüssel für die Token-Speicherung
     *
     * @param string $sessionKey Neuer Session-Schlüssel
     * @return self
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Setzt die maximale Anzahl gleichzeitig gespeicherter Tokens
     *
     * @param int $maxTokens Maximale Anzahl
     * @return self
     */
    public function setMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }

    /**
     * Gibt den Token-Generator zurück
     *
     * @return CsrfTokenGenerator
     */
    public function getGenerator(): CsrfTokenGenerator
    {
        return $this->generator;
    }
}