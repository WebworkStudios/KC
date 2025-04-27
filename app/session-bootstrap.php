<?php

/**
 * Session- und CSRF-Bootstrapping für die Anwendung
 *
 * Initialisiert den Session-Handler und CSRF-Schutz und registriert sie im Container
 */

use Src\Container\Container;
use Src\Http\Middleware\CsrfMiddleware;
use Src\Log\LoggerInterface;
use Src\Security\CsrfTokenGenerator;
use Src\Security\CsrfTokenManager;
use Src\Session\SessionFactory;
use Src\Session\SessionInterface;

/**
 * Session und CSRF initialisieren und im Container registrieren
 *
 * @param Container $container DI-Container
 * @param array $config Anwendungskonfiguration
 * @return void
 */
function bootstrapSession(Container $container, array $config): void
{
    // Logger abrufen
    $logger = $container->get(LoggerInterface::class);

    // Session-Konfiguration
    $sessionConfig = $config['session'] ?? include __DIR__ . '/Config/session.php';

    // Session-Factory erstellen und registrieren
    $sessionFactory = new SessionFactory($logger);
    $container->register(SessionFactory::class, $sessionFactory);

    // Umgebung bestimmen
    $environment = $config['app']['environment'] ?? 'development';

    try {
        // Session-Instanz erstellen
        $session = $sessionFactory->createDefaultSession($environment, $sessionConfig);

        // Session im Container registrieren
        $container->register(SessionInterface::class, $session);

        $logger->info("Session initialisiert", [
            'type' => get_class($session),
            'environment' => $environment
        ]);

        // CSRF-Schutz initialisieren, falls aktiviert
        if ($sessionConfig['csrf']['enabled'] ?? true) {
            bootstrapCsrf($container, $session, $sessionConfig['csrf'] ?? []);
        }
    } catch (\Throwable $e) {
        $logger->error("Fehler beim Initialisieren der Session: " . $e->getMessage(), [
            'exception' => get_class($e),
            'trace' => $e->getTraceAsString()
        ]);

        // Sollte nicht passieren, da die Standard-PHP-Session immer funktionieren sollte
        throw $e;
    }
}

/**
 * CSRF-Schutz initialisieren und im Container registrieren
 *
 * @param Container $container DI-Container
 * @param SessionInterface $session Session-Instanz
 * @param array $csrfConfig CSRF-Konfiguration
 * @return void
 */
function bootstrapCsrf(Container $container, SessionInterface $session, array $csrfConfig): void
{
    // Logger abrufen
    $logger = $container->get(LoggerInterface::class);

    try {
        // CSRF-Token-Generator erstellen
        $secret = $csrfConfig['secret'] ?? null;
        $lifetime = $csrfConfig['lifetime'] ?? 3600;
        $algorithm = $csrfConfig['algorithm'] ?? 'sha256';

        $tokenGenerator = new CsrfTokenGenerator($secret, $lifetime, $algorithm);
        $container->register(CsrfTokenGenerator::class, $tokenGenerator);

        // CSRF-Token-Manager erstellen
        $tokenManager = new CsrfTokenManager($session, $tokenGenerator, $logger);
        $container->register(CsrfTokenManager::class, $tokenManager);

        // CSRF-Middleware erstellen und registrieren
        $csrfMiddleware = new CsrfMiddleware($tokenManager, $logger, $csrfConfig);
        $container->register(CsrfMiddleware::class, $csrfMiddleware);

        $logger->info("CSRF-Schutz initialisiert", [
            'lifetime' => $lifetime,
            'algorithm' => $algorithm
        ]);
    } catch (\Throwable $e) {
        $logger->error("Fehler beim Initialisieren des CSRF-Schutzes: " . $e->getMessage(), [
            'exception' => get_class($e),
            'trace' => $e->getTraceAsString()
        ]);

        // Fehler weitergeben
        throw $e;
    }
}