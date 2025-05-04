<?php

namespace App\Actions;

use Src\Container\Container;
use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;
use Src\Log\LoggerInterface;
use Src\View\ViewFactory;
use Src\Database\DatabaseFactory;
use Throwable;


readonly class PlayerListAction
{
    public function __construct(
        private Container       $container,
        private LoggerInterface $logger
    ) {}


    #[Route(path: '/players', name: 'player.list')]
    public function __invoke(Request $request): Response
    {
        $view = $this->container->get(ViewFactory::class);

        $players = [];
        $error = null;

        try {
            // Create QueryBuilder instance for kickerscup database
            $query = DatabaseFactory::createQueryBuilder('kickerscup');

            // Get all players from the players table
            $players = $query->table('players')
                ->select(['id', 'name', 'email', 'created_at'])
                ->orderBy('name')
                ->get();

            $this->logger->info('Successfully fetched players from database', [
                'count' => count($players)
            ]);

        } catch (Throwable $e) {
            $this->logger->error('Failed to fetch players from database', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $error = $e->getMessage();
        }

        return $view->render('players/list', [
            'title' => 'Player List',
            'players' => $players,
            'error' => $error
        ]);
    }
}