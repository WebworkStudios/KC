<?php

namespace Src\Log;

/**
 * Logger, der alle Logeinträge verwirft
 *
 * Nützlich für Tests oder wenn Logging deaktiviert werden soll
 */
class NullLogger implements LoggerInterface
{
    /**
     * {@inheritDoc}
     */
    public function emergency(string $message, array $context = []): void
    {
        // Nichts tun
    }

    /**
     * {@inheritDoc}
     */
    public function alert(string $message, array $context = []): void
    {
        // Nichts tun
    }

    /**
     * {@inheritDoc}
     */
    public function critical(string $message, array $context = []): void
    {
        // Nichts tun
    }

    /**
     * {@inheritDoc}
     */
    public function error(string $message, array $context = []): void
    {
        // Nichts tun
    }

    /**
     * {@inheritDoc}
     */
    public function warning(string $message, array $context = []): void
    {
        // Nichts tun
    }

    /**
     * {@inheritDoc}
     */
    public function notice(string $message, array $context = []): void
    {
        // Nichts tun
    }

    /**
     * {@inheritDoc}
     */
    public function info(string $message, array $context = []): void
    {
        // Nichts tun
    }

    /**
     * {@inheritDoc}
     */
    public function debug(string $message, array $context = []): void
    {
        // Nichts tun
    }

    /**
     * {@inheritDoc}
     */
    public function log(string $level, string $message, array $context = []): void
    {
        // Nichts tun
    }
}