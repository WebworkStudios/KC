<?php
declare(strict_types=1);
namespace Src\Security;

class CsrfProtection
{
    private Session $session;
    private string $tokenName;
    private int $tokenExpiration;

    /**
     * Create a new CSRF protection instance
     */
    public function __construct(
        Session $session,
        string $tokenName = 'csrf_token',
        int    $tokenExpiration = 7200 // 2 hours in seconds
    )
    {
        $this->session = $session;
        $this->tokenName = $tokenName;
        $this->tokenExpiration = $tokenExpiration;
    }

    /**
     * Generate a new CSRF token
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $expiration = time() + $this->tokenExpiration;

        $this->session->set($this->tokenName, [
            'token' => $token,
            'expires' => $expiration
        ]);

        return $token;
    }

    /**
     * Get the current token or generate a new one
     */
    public function getToken(): string
    {
        if (!$this->hasValidToken()) {
            return $this->generateToken();
        }

        $tokenData = $this->session->get($this->tokenName);
        return $tokenData['token'];
    }

    /**
     * Check if there's a valid token in the session
     */
    private function hasValidToken(): bool
    {
        $tokenData = $this->session->get($this->tokenName);

        if (!is_array($tokenData) || !isset($tokenData['token']) || !isset($tokenData['expires'])) {
            return false;
        }

        // Check if token is expired
        if ($tokenData['expires'] < time()) {
            $this->session->remove($this->tokenName);
            return false;
        }

        return true;
    }

    /**
     * Validate a submitted token
     */
    public function validateToken(?string $token): bool
    {
        if ($token === null || !$this->hasValidToken()) {
            return false;
        }

        $tokenData = $this->session->get($this->tokenName);
        $valid = hash_equals($tokenData['token'], $token);

        // Generate a new token after validation for next request
        if ($valid) {
            $this->generateToken();
        }

        return $valid;
    }

    /**
     * Get HTML for a hidden CSRF token field
     */
    public function tokenField(): string
    {
        $token = $this->getToken();
        return '<input type="hidden" name="' . $this->tokenName . '" value="' . $token . '">';
    }

    /**
     * Get token name
     */
    public function getTokenName(): string
    {
        return $this->tokenName;
    }
}