<?php

namespace Src\Http\Middleware;

use Src\Http\Middleware;
use Src\Http\Request;
use Src\Http\Response;
use Src\Log\LoggerInterface;

/**
 * Middleware zum Protokollieren von HTTP-Anfragen
 */
readonly class LoggingMiddleware implements Middleware
{
    /**
     * Erstellt eine neue LoggingMiddleware
     *
     * @param LoggerInterface $logger Logger für Anfragen
     */
    public function __construct(
        private LoggerInterface $logger
    )
    {
    }

    /**
     * {@inheritDoc}
     */
    public function process(Request $request, callable $next): ?Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // Anfang der Anfrage protokollieren
        $this->logger->info("Eingehende {$method}-Anfrage an {$path}", [
            'method' => $method,
            'path' => $path,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unbekannt',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unbekannt',
        ]);

        $startTime = microtime(true);

        // Anfrage weiterleiten
        $response = $next($request);

        $duration = microtime(true) - $startTime;
        $durationMs = round($duration * 1000, 2);

        // Status und Dauer protokollieren
        $status = $response ? $response->getStatus() : 0;

        $logMethod = $this->getLogMethodForStatus($status);
        $this->logger->$logMethod("Antwort für {$method} {$path}: Status {$status}, Dauer {$durationMs}ms", [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'duration_ms' => $durationMs,
        ]);

        return $response;
    }

    /**
     * Bestimmt die zu verwendende Log-Methode basierend auf dem HTTP-Status
     *
     * @param int $status HTTP-Status
     * @return string Log-Methode (error, warning, info)
     */
    private function getLogMethodForStatus(int $status): string
    {
        if ($status >= 500) {
            return 'error';
        }

        if ($status >= 400) {
            return 'warning';
        }

        return 'info';
    }
}