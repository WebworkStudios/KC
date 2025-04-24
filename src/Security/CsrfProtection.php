<?php


namespace Src\Security;

class CsrfProtection
{
    private string $tokenName;
    private int $tokenExpiration;

    /**
     * Create a new CSRF protection instance
     */
    public function __construct(
        string $tokenName = 'csrf_token',
        int    $tokenExpiration = 7200 // 2 hours in seconds
    )
    {
        $this->tokenName = $tokenName;
        $this->tokenExpiration = $tokenExpiration;

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Generate a new CSRF token
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $expiration = time() + $this->tokenExpiration;

        $_SESSION[$this->tokenName] = [
            'token' => $token,
            'expires' => $expiration
        ];

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

        return $_SESSION[$this->tokenName]['token'];
    }

    /**
     * Check if there's a valid token in the session
     */
    private function hasValidToken(): bool
    {
        if (!isset($_SESSION[$this->tokenName]) ||
            !isset($_SESSION[$this->tokenName]['token']) ||
            !isset($_SESSION[$this->tokenName]['expires'])) {
            return false;
        }

        // Check if token is expired
        if ($_SESSION[$this->tokenName]['expires'] < time()) {
            unset($_SESSION[$this->tokenName]);
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

        $valid = hash_equals($_SESSION[$this->tokenName]['token'], $token);

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