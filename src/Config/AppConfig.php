<?php


namespace Src\Config;

class AppConfig
{
    private array $configs = [];

    /**
     * Create a new config instance
     */
    public function __construct(array $configs = [])
    {
        $this->configs = $configs;
    }

    /**
     * Load config from a file
     */
    public function loadFromFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Config file does not exist: $path");
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new \InvalidArgumentException("Config file must return an array: $path");
        }

        $this->configs = array_merge($this->configs, $config);
    }

    /**
     * Load config from a directory of PHP files
     */
    public function loadFromDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Config directory does not exist: $directory");
        }

        foreach (glob("$directory/*.php") as $file) {
            $name = basename($file, '.php');
            $config = require $file;

            if (!is_array($config)) {
                throw new \InvalidArgumentException("Config file must return an array: $file");
            }

            $this->configs[$name] = $config;
        }
    }

    /**
     * Get a config value by key
     */
    public function get(string $key, $default = null)
    {
        // Handle dot notation (e.g., 'database.host')
        if (str_contains($key, '.')) {
            return $this->getDotNotation($key, $default);
        }

        return $this->configs[$key] ?? $default;
    }

    /**
     * Get a config value using dot notation
     */
    private function getDotNotation(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->configs;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set a config value
     */
    public function set(string $key, $value): void
    {
        // Handle dot notation
        if (str_contains($key, '.')) {
            $this->setDotNotation($key, $value);
            return;
        }

        $this->configs[$key] = $value;
    }

    /**
     * Set a config value using dot notation
     */
    private function setDotNotation(string $key, $value): void
    {
        $keys = explode('.', $key);
        $reference = &$this->configs;

        foreach ($keys as $i => $segment) {
            // If we're at the last segment, set the value
            if ($i === count($keys) - 1) {
                $reference[$segment] = $value;
                break;
            }

            // If the segment doesn't exist or isn't an array, create it
            if (!isset($reference[$segment]) || !is_array($reference[$segment])) {
                $reference[$segment] = [];
            }

            $reference = &$reference[$segment];
        }
    }

    /**
     * Check if a config key exists
     */
    public function has(string $key): bool
    {
        // Handle dot notation
        if (str_contains($key, '.')) {
            $keys = explode('.', $key);
            $value = $this->configs;

            foreach ($keys as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return false;
                }

                $value = $value[$segment];
            }

            return true;
        }

        return array_key_exists($key, $this->configs);
    }

    /**
     * Get all config values
     */
    public function all(): array
    {
        return $this->configs;
    }
}