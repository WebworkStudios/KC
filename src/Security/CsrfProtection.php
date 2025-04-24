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
     *
     * @param Session $session The session instance
     * @param string $tokenName The name for the CSRF token
     * @param int $tokenExpiration The token expiration time in seconds
     */
    public function __construct(
        Session $session,
        string $tokenName = 'csrf_token',
        int $tokenExpiration = 7200 // 2 hours in seconds
    ) {
        $this->session = $session;
        $this->tokenName = $tokenName;
        $this->tokenExpiration = $tokenExpiration;
    }

    /**
     * Generate a new CSRF token
     *
     * @return string The generated token
     */
    public function generateToken(): string
    {
        $token = bin2hex($this->generateRandomBytes(32));
        $expiration = time() + $this->tokenExpiration;

        $this->session->set($this->tokenName, [
            'token' => $token,
            'expires' => $expiration
        ]);

        return $token;
    }

    /**
     * Generate cryptographically secure random bytes
     *
     * @param int $length The length of random bytes to generate
     * @return string The generated random bytes
     */
    protected function generateRandomBytes(int $length): string
    {
        return random_bytes($length);
    }

    /**
     * Get the current token or generate a new one
     *
     * @return string The current or newly generated token
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
     *
     * @return bool True if a valid token exists, false otherwise
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
     *
     * @param string|null $token The token to validate
     * @param bool $regenerateToken Whether to regenerate the token after validation
     * @return bool True if token is valid, false otherwise
     */
    public function validateToken(?string $token, bool $regenerateToken = true): bool
    {
        if ($token === null || !$this->hasValidToken()) {
            return false;
        }

        $tokenData = $this->session->get($this->tokenName);
        $valid = hash_equals($tokenData['token'], $token);

        // Generate a new token after validation for next request, if requested
        if ($valid === true && $regenerateToken === true) {
            $this->generateToken();
        }

        return $valid;
    }

    /**
     * Generate a specific token for a form
     *
     * @param string $formId Unique identifier for the form
     * @return string The generated form-specific token
     */
    public function generateFormToken(string $formId): string
    {
        $token = bin2hex($this->generateRandomBytes(32));
        $expiration = time() + $this->tokenExpiration;

        // Store form-specific tokens
        $formTokens = $this->session->get('form_csrf_tokens', []);
        $formTokens[$formId] = [
            'token' => $token,
            'expires' => $expiration
        ];

        $this->session->set('form_csrf_tokens', $formTokens);

        return $token;
    }

    /**
     * Validate a form-specific token
     *
     * @param string $formId Unique identifier for the form
     * @param string|null $token The token to validate
     * @return bool True if token is valid, false otherwise
     */
    public function validateFormToken(string $formId, ?string $token): bool
    {
        if ($token === null) {
            return false;
        }

        $formTokens = $this->session->get('form_csrf_tokens', []);

        if (!isset($formTokens[$formId]) ||
            !isset($formTokens[$formId]['token']) ||
            !isset($formTokens[$formId]['expires'])) {
            return false;
        }

        // Check if token is expired
        if ($formTokens[$formId]['expires'] < time()) {
            unset($formTokens[$formId]);
            $this->session->set('form_csrf_tokens', $formTokens);
            return false;
        }

        $valid = hash_equals($formTokens[$formId]['token'], $token);

        // Remove the token after validation to prevent reuse
        if ($valid === true) {
            unset($formTokens[$formId]);
            $this->session->set('form_csrf_tokens', $formTokens);
        }

        return $valid;
    }

    /**
     * Generate a token and store it both in session and as a cookie
     *
     * @return string The generated token
     */
    public function generateDoubleSubmitToken(): string
    {
        $token = $this->generateToken();

        // Set secure cookie with the same token
        setcookie(
            $this->tokenName . '_cookie',
            $token,
            [
                'expires' => time() + $this->tokenExpiration,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => false, // Must be accessible via JavaScript
                'samesite' => 'Strict'
            ]
        );

        return $token;
    }

    /**
     * Validate using double submit pattern (session + cookie)
     *
     * @param string|null $token The token to validate
     * @return bool True if both session and cookie tokens are valid, false otherwise
     */
    public function validateDoubleSubmitToken(?string $token): bool
    {
        // Cookie token must match session token and submitted token
        $cookieToken = $_COOKIE[$this->tokenName . '_cookie'] ?? null;

        if ($token === null || $cookieToken === null || !$this->hasValidToken()) {
            return false;
        }

        $tokenData = $this->session->get($this->tokenName);

        $validSession = hash_equals($tokenData['token'], $token);
        $validCookie = hash_equals($tokenData['token'], $cookieToken);

        $valid = $validSession && $validCookie;

        if ($valid === true) {
            $this->generateDoubleSubmitToken(); // Regenerate for next request
        }

        return $valid;
    }

    /**
     * Get HTML for a hidden CSRF token field
     *
     * @return string HTML input field with CSRF token
     */
    public function tokenField(): string
    {
        $token = $this->getToken();
        return '<input type="hidden" name="' . $this->tokenName . '" value="' . $token . '">';
    }

    /**
     * Get HTML for a hidden form-specific CSRF token field
     *
     * @param string $formId Unique identifier for the form
     * @return string HTML input field with form-specific CSRF token
     */
    public function formTokenField(string $formId): string
    {
        $token = $this->generateFormToken($formId);
        return '<input type="hidden" name="' . $this->tokenName . '_' . $formId . '" value="' . $token . '">';
    }

    /**
     * Get token name
     *
     * @return string The token name
     */
    public function getTokenName(): string
    {
        return $this->tokenName;
    }

    /**
     * Get token expiration time in seconds
     *
     * @return int The token expiration time
     */
    public function getTokenExpiration(): int
    {
        return $this->tokenExpiration;
    }

    /**
     * Set token expiration time in seconds
     *
     * @param int $seconds New expiration time in seconds
     * @return self Fluent interface
     */
    public function setTokenExpiration(int $seconds): self
    {
        $this->tokenExpiration = $seconds;
        return $this;
    }

    /**
     * Garbage collect expired form tokens
     *
     * @return int Number of expired tokens removed
     */
    public function cleanupExpiredFormTokens(): int
    {
        $formTokens = $this->session->get('form_csrf_tokens', []);
        $currentCount = count($formTokens);
        $now = time();

        foreach ($formTokens as $formId => $tokenData) {
            if (isset($tokenData['expires']) && $tokenData['expires'] < $now) {
                unset($formTokens[$formId]);
            }
        }

        $this->session->set('form_csrf_tokens', $formTokens);
        return $currentCount - count($formTokens);
    }
}