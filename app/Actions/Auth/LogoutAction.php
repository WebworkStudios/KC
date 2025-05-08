<?php


namespace App\Actions\Auth;

use App\Domain\Services\AuthService;
use Src\Container\Container;
use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;
use Src\Log\LoggerInterface;
use Src\Session\SessionInterface;

class LogoutAction
{
    private AuthService $authService;
    private SessionInterface $session;

    public function __construct(
        Container       $container,
        LoggerInterface $logger
    )
    {
        $this->authService = $container->get(AuthService::class);
        $this->session = $container->get(SessionInterface::class);
    }

    #[Route(path: '/logout', name: 'auth.logout', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $this->authService->logout();
        $this->session->flash('success', 'Du wurdest erfolgreich abgemeldet');
        return Response::redirect('/');
    }
}