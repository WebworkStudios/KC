<?php

namespace Src\Utils;

use Src\Config\AppConfig;

class Logger
{
    private string $logPath;
    private string $logLevel;
    private array $levels = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600
    ];

    /**
     * Create a new logger instance
     */
    public function __construct(AppConfig $config)
    {
        $this->logPath = $config->get('logger.path', 'logs/app.log');
        $this->logLevel = $config->get('logger.level', 'debug');

        // Ensure log directory exists
        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * Log a debug message
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log an info message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log a notice message
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log an error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Log a critical message
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * Log an alert message
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * Log an emergency message
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * Log a message with any level
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!isset($this->levels[$level]) || $this->levels[$level] < $this->levels[$this->logLevel]) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextString = empty($context) ? '' : ' ' . json_encode($context);

        $logEntry = "[{$timestamp}] [{$level}]: {$message}{$contextString}" . PHP_EOL;

        file_put_contents($this->logPath, $logEntry, FILE_APPEND);
    }

    /**
     * Interpolate context values into message placeholders
     */
    private function interpolate(string $message, array $context = []): string
    {
        // Build a replacement array with braces around the context keys
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || is_numeric($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        // Interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    /**
     * Set the minimum logging level
     */
    public function setLogLevel(string $level): void
    {
        if (isset($this->levels[$level])) {
            $this->logLevel = $level;
        }
    }

    /**
     * Get the current log level
     */
    public function getLogLevel(): string
    {
        return $this->logLevel;
    }

    /**
     * Clear the log file
     */
    public function clearLog(): bool
    {
        return file_put_contents($this->logPath, '') !== false;
    }
}