<?php


namespace App\Actions\Auth;

use App\Domain\Services\AuthService;
use Src\Container\Container;
use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;
use Src\Log\LoggerInterface;
use Src\Session\SessionInterface;

class ActivateAccountAction
{
    private AuthService $authService;
    private LoggerInterface $logger;
    private SessionInterface $session;

    public function __construct(
        Container       $container,
        LoggerInterface $logger
    )
    {
        $this->authService = $container->get(AuthService::class);
        $this->logger = $logger;
        $this->session = $container->get(SessionInterface::class);
    }

    #[Route(path: '/activate/{token}', name: 'auth.activate', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $token = $request->getRouteParameter('token');

        if (empty($token)) {
            $this->session->flash('error', 'Ungültiger Aktivierungslink');
            return Response::redirect('/');
        }

        $result = $this->authService->activateAccount($token);

        if (!$result['success']) {
            $this->session->flash('error', $result['message']);
            return Response::redirect('/');
        }

        $this->session->flash('success', 'Dein Konto wurde erfolgreich aktiviert. Du kannst dich jetzt anmelden.');
        return Response::redirect('/login');
    }
}