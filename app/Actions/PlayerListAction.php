<?php

namespace App\Actions;

use Src\Cache\CacheInterface;
use Src\Database\DatabaseFactory;
use Src\Database\QueryBuilder;
use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;
use Src\Log\LoggerInterface;
use Src\View\ViewFactory;
use Throwable;

/**
 * Action zum Anzeigen aller Spieler
 */
readonly class PlayerListAction
{
    /**
     * Erstellt eine neue PlayerListAction
     *
     * @param ViewFactory $viewFactory View-Factory für Templates
     * @param LoggerInterface $logger Logger für Fehler und Informationen
     * @param CacheInterface|null $cache Optional: Cache für Spielerlisten
     */
    public function __construct(
        private ViewFactory $viewFactory,
        private LoggerInterface $logger,
        private ?CacheInterface $cache = null
    ) {
    }

    /**
     * Verarbeitet die Anfrage und gibt eine Response zurück
     *
     * @param Request $request HTTP-Anfrage
     * @return Response HTTP-Antwort
     */
    #[Route(path: '/players', name: 'player.list', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Daten definieren
        $players = [];
        $error = null;
        $playersCount = 0;
        $cacheKey = 'players.list';

        try {
            // Versuche Spieler aus dem Cache zu laden, falls ein Cache verfügbar ist
            if ($this->cache !== null && $this->cache->has($cacheKey)) {
                $cachedData = $this->cache->get($cacheKey);

                if (is_array($cachedData) && isset($cachedData['players'])) {
                    $this->logger->info('Spielerliste aus Cache geladen');

                    $players = $cachedData['players'];
                    $playersCount = count($players);
                }
            } else {
                // Cache-Miss oder kein Cache konfiguriert, Daten aus Datenbank laden
                $query = $this->createQueryBuilder();

                // Alle Spieler abfragen mit Sortierung nach ID
                $players = $query->table('players')
                    ->select(['player_id', 'first_name', 'last_name', 'created_date'])
                    ->orderBy('player_id')
                    ->get();

                $playersCount = count($players);

                $this->logger->info('Spielerliste aus Datenbank geladen', [
                    'count' => $playersCount
                ]);

                // Im Cache speichern, falls verfügbar
                if ($this->cache !== null) {
                    $this->cache->set($cacheKey, [
                        'players' => $players,
                        'count' => $playersCount
                    ], 3600); // 1 Stunde cachen

                    $this->logger->debug('Spielerliste im Cache gespeichert');
                }
            }
        } catch (Throwable $e) {
            // Fehler protokollieren
            $this->logger->error('Fehler beim Laden der Spielerliste', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $error = $e->getMessage();
        }

        // View mit Daten rendern
        return $this->viewFactory->render('players/list', [
            'title' => 'Spielerliste',
            'players' => $players,
            'playersCount' => $playersCount,
            'error' => $error
        ]);
    }

    /**
     * Erstellt einen QueryBuilder für die Datenbank
     *
     * @return QueryBuilder
     */
    private function createQueryBuilder(): QueryBuilder
    {
        return DatabaseFactory::createQueryBuilder('kickerscup');
    }
}