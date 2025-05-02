<?php


/**
 * Queue-Konfiguration
 *
 * Erstellt die Konfigurationen für verschiedene Queues
 */

use Src\Queue\Connection\MySQLConnectionAdapterFactory;
use Src\Queue\QueueConfig;
use Src\Queue\RetryStrategy;

/**
 * Erstellt eine Queue-Konfiguration
 *
 * @param string $queueName Name der Queue
 * @param array $options Optionale Konfigurationsoptionen
 * @return QueueConfig Queue-Konfiguration
 */
function create_queue_config(string $queueName, array $options = []): QueueConfig
{
    // Standardoptionen
    $defaults = [
        'connection' => 'default',        // Datenbankverbindung
        'table' => 'queue_jobs',          // Tabelle für Jobs
        'failed_table' => 'queue_failed_jobs', // Tabelle für fehlgeschlagene Jobs
        'recurring_table' => 'queue_recurring_jobs', // Tabelle für wiederkehrende Jobs
        'max_retries' => 3,               // Maximale Anzahl an Wiederholungsversuchen
        'retry_delay' => 60,              // Verzögerung zwischen Wiederholungsversuchen in Sekunden
        'retry_strategy' => RetryStrategy::EXPONENTIAL, // Strategie für Wiederholungsversuche
        'default_priority' => 0,          // Standardpriorität für Jobs
        'default_delay' => 0,             // Standardverzögerung für Jobs in Sekunden
        'auto_prune' => true,             // Ob alte Jobs automatisch bereinigt werden sollen
        'max_age' => 604800,              // Maximales Alter für alte Jobs in Sekunden (7 Tage)
        'max_execution_time' => 60,       // Maximale Ausführungszeit für Jobs in Sekunden
        'store_failed_jobs' => true,      // Ob fehlgeschlagene Jobs gespeichert werden sollen
        'batch_size' => 10,               // Maximale Anzahl an Jobs pro Batch
        'support_unique_jobs' => true,    // Ob eindeutige Jobs unterstützt werden sollen
        'unique_jobs_expiration' => 86400, // Wie lange eindeutige Jobs einzigartig bleiben (24 Stunden)
    ];

    // Optionen mit Standardwerten zusammenführen
    $options = array_merge($defaults, $options);

    // ConnectionFactory erstellen
    $connectionFactory = new MySQLConnectionAdapterFactory(
        $options['connection'],
        $options['table'],
        $options['failed_table'],
        $options['recurring_table']
    );

    // QueueConfig erstellen
    $config = new QueueConfig($connectionFactory);

    // Weitere Optionen setzen
    $config->setMaxRetries($options['max_retries']);
    $config->setRetryDelay($options['retry_delay']);
    $config->setRetryStrategy($options['retry_strategy']);
    $config->setDefaultPriority($options['default_priority']);
    $config->setDefaultDelay($options['default_delay']);
    $config->setAutoPrune($options['auto_prune']);
    $config->setMaxAge($options['max_age']);
    $config->setMaxExecutionTime($options['max_execution_time']);
    $config->setStoreFailedJobs($options['store_failed_jobs']);
    $config->setBatchSize($options['batch_size']);
    $config->setSupportUniqueJobs($options['support_unique_jobs']);
    $config->setUniqueJobsExpiration($options['unique_jobs_expiration']);

    return $config;
}

// Erstellt Konfigurationen für verschiedene Queues
return [
    'create_config' => function (string $queueName, array $options = []): QueueConfig {
        return create_queue_config($queueName, $options);
    },

    // Vordefinierte Konfigurationen
    'default' => create_queue_config('default'),
    'emails' => create_queue_config('emails', [
        'max_retries' => 5,
        'retry_delay' => 300, // 5 Minuten
        'max_execution_time' => 30,
    ]),
    'exports' => create_queue_config('exports', [
        'max_retries' => 2,
        'max_execution_time' => 300, // 5 Minuten
        'batch_size' => 5,
    ]),
];