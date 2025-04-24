<?php
declare(strict_types=1);
namespace Src\Security;

class Session
{
    /**
     * Starts the session if it's not already active
     */
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Sets a value in the session
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Gets a value from the session
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
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
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
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
     * Stores a flash message in the session
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function flash(string $key, $value): void
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
    public function getFlash(string $key, $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;

        if (isset($_SESSION['_flash'][$key])) {
            unset($_SESSION['_flash'][$key]);
        }

        return $value;
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
    public function getOldInput(string $key, $default = null): mixed
    {
        return $_SESSION['_old_input'][$key] ?? $default;
    }
}