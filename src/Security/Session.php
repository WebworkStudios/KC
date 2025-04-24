<?php
declare(strict_types=1);
namespace Src\Security;

class Session
{
    /**
     * Create a new session instance with secure defaults
     */
    public function __construct(
        private readonly bool $secure = true,
        private readonly bool $httpOnly = true,
        private readonly string $sameSite = 'Lax'
    ) {
        if (session_status() === PHP_SESSION_NONE) {
            $this->startSecureSession();
        }
    }

    /**
     * Starts the session with secure settings
     */
    private function startSecureSession(): void
    {
        $cookieParams = session_get_cookie_params();

        session_set_cookie_params([
            'lifetime' => $cookieParams['lifetime'],
            'path' => $cookieParams['path'],
            'domain' => $cookieParams['domain'],
            'secure' => $this->secure, // Require HTTPS
            'httponly' => $this->httpOnly, // Prevent JavaScript access
            'samesite' => $this->sameSite // Prevent CSRF
        ]);

        session_start();
    }

    /**
     * Safely performs a session operation with error handling
     *
     * @param callable $operation The session operation to perform
     * @param mixed $default Default value if operation fails
     * @return mixed Result of operation or default
     */
    private function safeSessionOperation(callable $operation, mixed $default = null): mixed
    {
        try {
            return $operation();
        } catch (\Exception $e) {
            // Optionally log the error
            return $default;
        }
    }

    /**
     * Sets a value in the session
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->safeSessionOperation(function() use ($key, $value) {
            $_SESSION[$key] = $value;
        });
    }

    /**
     * Gets a value from the session
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Sets a nested array value in the session
     *
     * @param string $key Dot notation key (e.g., 'user.preferences.theme')
     * @param mixed $value Value to set
     * @return void
     */
    public function setNested(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $array = &$_SESSION;

        while (count($keys) > 1) {
            $currentKey = array_shift($keys);

            if (!isset($array[$currentKey]) || !is_array($array[$currentKey])) {
                $array[$currentKey] = [];
            }

            $array = &$array[$currentKey];
        }

        $array[array_shift($keys)] = $value;
    }

    /**
     * Gets a nested array value from the session
     *
     * @param string $key Dot notation key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getNested(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $array = $_SESSION;

        foreach ($keys as $currentKey) {
            if (!isset($array[$currentKey])) {
                return $default;
            }

            $array = $array[$currentKey];
        }

        return $array;
    }

    /**
     * Checks if a key exists in the session
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Removes a value from the session
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void
    {
        if ($this->has($key)) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Clears all data from the session
     *
     * @return void
     */
    public function clear(): void
    {
        $_SESSION = [];
    }

    /**
     * Destroys the session including the cookie
     *
     * @return bool
     */
    public function destroy(): bool
    {
        $this->clear();

        // Delete the session cookie if it exists
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params["path"],
                    'domain' => $params["domain"],
                    'secure' => $params["secure"],
                    'httponly' => $params["httponly"],
                    'samesite' => $this->sameSite
                ]
            );
        }

        return session_destroy();
    }

    /**
     * Generates a new session ID
     *
     * @param bool $deleteOldSession
     * @return bool
     */
    public function regenerateId(bool $deleteOldSession = true): bool
    {
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Checks session validity and regenerates ID if necessary
     *
     * @param int $maxLifetime Maximum session lifetime in seconds
     * @return bool Whether the session is valid
     */
    public function validateSession(int $maxLifetime = 1800): bool
    {
        // Check if session has expired
        if (!isset($_SESSION['_last_activity'])) {
            $_SESSION['_last_activity'] = time();
            return true;
        }

        $lastActivity = $_SESSION['_last_activity'];
        $_SESSION['_last_activity'] = time();

        // Check if the session has expired
        if (time() - $lastActivity > $maxLifetime) {
            $this->destroy();
            return false;
        }

        // Regenerate ID periodically for security
        if (!isset($_SESSION['_regen_time']) || time() - $_SESSION['_regen_time'] > 300) {
            $this->regenerateId();
            $_SESSION['_regen_time'] = time();
        }

        return true;
    }

    /**
     * Stores a flash message in the session
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Gets a flash value from the session and removes it
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;

        if (isset($_SESSION['_flash'][$key])) {
            unset($_SESSION['_flash'][$key]);
        }

        return $value;
    }

    /**
     * Removes all expired flash data from session
     *
     * @return int Number of removed items
     */
    public function clearExpiredFlashData(): int
    {
        if (!isset($_SESSION['_flash'])) {
            return 0;
        }

        $initialCount = count($_SESSION['_flash']);

        // Keep only non-empty flash data
        $_SESSION['_flash'] = array_filter($_SESSION['_flash'], function($value) {
            return $value !== null;
        });

        return $initialCount - count($_SESSION['_flash']);
    }

    /**
     * Stores old input values in the session
     *
     * @param array $input
     * @return void
     */
    public function setOldInput(array $input): void
    {
        $_SESSION['_old_input'] = $input;
    }

    /**
     * Gets an old input value from the session
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getOldInput(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_old_input'][$key] ?? $default;
    }

    /**
     * Sets the user consent status
     *
     * @param bool $hasConsent Whether the user has given consent
     * @return void
     */
    public function setConsentStatus(bool $hasConsent): void
    {
        $_SESSION['user_consent'] = $hasConsent;
    }

    /**
     * Checks if the user has given consent
     *
     * @return bool Whether the user has given consent
     */
    public function hasUserConsent(): bool
    {
        return $_SESSION['user_consent'] ?? false;
    }

    /**
     * Set a custom session handler
     *
     * @param \SessionHandlerInterface $handler
     * @return bool Success status
     */
    public function setHandler(\SessionHandlerInterface $handler): bool
    {
        return session_set_save_handler($handler, true);
    }
}