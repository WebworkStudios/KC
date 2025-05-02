<?php


namespace Src\Queue\Connection;

use Src\Container\Container;
use Src\Log\LoggerInterface;

/**
 * Interface für Factories, die Queue-Verbindungen erstellen
 */
interface ConnectionFactoryInterface
{
    /**
     * Erstellt eine neue Queue-Verbindung
     *
     * @param string $queueName Name der Queue
     * @param Container $container DI-Container
     * @param LoggerInterface $logger Logger für Queue-Operationen
     * @return ConnectionInterface Neue Queue-Verbindung
     */
    public function createConnection(
        string          $queueName,
        Container       $container,
        LoggerInterface $logger
    ): ConnectionInterface;
}