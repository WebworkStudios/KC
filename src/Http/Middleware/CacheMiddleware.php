<?php

namespace Src\Http\Middleware;

use Src\Cache\CacheInterface;
use Src\Http\Middleware;
use Src\Http\Request;
use Src\Http\Response;
use Src\Log\LoggerInterface;

/**
 * Middleware für HTTP-Response-Caching
 *
 * Ermöglicht das Caching von HTTP-Antworten zur Leistungsoptimierung
 */
readonly class CacheMiddleware implements Middleware
{
    /**
     * Erstellt eine neue CacheMiddleware
     *
     * @param CacheInterface $cache Cache-Implementierung
     * @param LoggerInterface $logger Logger für Cache-Operationen
     * @param int|null $ttl Standard-TTL für Cache-Einträge in Sekunden (null = unbegrenzt)
     * @param bool $cacheByQueryParams Berücksichtigt Query-Parameter für den Cache-Schlüssel
     */
    public function __construct(
        private CacheInterface  $cache,
        private LoggerInterface $logger,
        private ?int            $ttl = 3600,
        private bool            $cacheByQueryParams = true
    )
    {
    }

    /**
     * {@inheritDoc}
     */
    public function process(Request $request, callable $next): ?Response
    {
        // Nur GET-Anfragen cachen
        if ($request->getMethod() !== 'GET') {
            return $next($request);
        }

        $cacheKey = $this->generateCacheKey($request);

        // Prüfen, ob die Antwort bereits im Cache ist
        if ($this->cache->has($cacheKey)) {
            $cachedResponse = $this->cache->get($cacheKey);

            if ($cachedResponse instanceof Response) {
                $this->logger->info("Cache-Hit für Anfrage", [
                    'path' => $request->getPath(),
                    'cache_key' => $cacheKey
                ]);

                // Header für Debugging hinzufügen
                $cachedResponse->setHeader('X-Cache', 'HIT');
                return $cachedResponse;
            }

            // Ungültiger Cache-Eintrag, löschen
            $this->cache->delete($cacheKey);
        }

        // Cache-Miss, Anfrage verarbeiten
        $response = $next($request);

        // Wenn es eine gültige Response gibt, diese cachen
        if ($response instanceof Response && $this->isCacheable($response)) {
            $this->cache->set($cacheKey, $response, $this->ttl);

            $this->logger->info("Response gecached", [
                'path' => $request->getPath(),
                'cache_key' => $cacheKey,
                'ttl' => $this->ttl
            ]);

            // Header für Debugging hinzufügen
            $response->setHeader('X-Cache', 'MISS');
        }

        return $response;
    }

    /**
     * Generiert einen Cache-Schlüssel für die Anfrage
     *
     * @param Request $request HTTP-Anfrage
     * @return string Cache-Schlüssel
     */
    private function generateCacheKey(Request $request): string
    {
        $key = 'http_cache:' . $request->getMethod() . ':' . $request->getPath();

        // Query-Parameter zum Cache-Schlüssel hinzufügen, wenn konfiguriert
        if ($this->cacheByQueryParams) {
            // GET-Parameter sortieren und zum Schlüssel hinzufügen
            $queryParams = [];

            foreach ($request->getServer() as $name => $value) {
                if (str_starts_with($name, 'QUERY_')) {
                    $queryParams[$name] = $value;
                }
            }

            if (!empty($queryParams)) {
                ksort($queryParams);
                $key .= ':' . md5(serialize($queryParams));
            }
        }

        return $key;
    }

    /**
     * Prüft, ob eine Response cacheable ist
     *
     * @param Response $response Zu prüfende Response
     * @return bool True, wenn die Response cacheable ist
     */
    private function isCacheable(Response $response): bool
    {
        // Erfolgreiche Responses cachen
        if ($response->getStatus() !== 200) {
            return false;
        }

        // Nur bestimmte Content-Types cachen
        $headers = $response->getHeaders();
        $contentType = $headers['Content-Type'] ?? '';

        // HTML, JSON, XML, Text cachen
        $cacheableTypes = [
            'text/html',
            'application/json',
            'application/xml',
            'text/xml',
            'text/plain',
        ];

        foreach ($cacheableTypes as $type) {
            if (str_starts_with($contentType, $type)) {
                return true;
            }
        }

        return false;
    }
}