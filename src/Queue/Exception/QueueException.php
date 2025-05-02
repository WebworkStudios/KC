<?php


namespace Src\Queue\Exception;

use RuntimeException;

/**
 * Basisklasse für Queue-Exceptions
 */
class QueueException extends RuntimeException
{
}

/**
 * Exception für fehlerhafte Job-Verarbeitung
 */
class JobException extends QueueException
{
}

/**
 * Exception für fehlerhafte Job-Serialisierung
 */
class JobSerializationException extends QueueException
{
}

/**
 * Exception für Timeout bei Job-Verarbeitung
 */
class JobTimeoutException extends QueueException
{
}

/**
 * Exception für fehlgeschlagene Wiederholungsversuche
 */
class MaxRetriesExceededException extends QueueException
{
}

/**
 * Exception für fehlerhafte Konfiguration
 */
class ConfigurationException extends QueueException
{
}