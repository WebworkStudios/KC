<?php


namespace App\Actions\Auth;

use App\Domain\Services\AuthService;
use Src\Container\Container;
use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;
use Src\Log\LoggerInterface;
use Src\View\ViewFactory;
use Src\Security\CsrfTokenManager;

class RegisterAction
{
    private AuthService $authService;
    private LoggerInterface $logger;
    private ViewFactory $viewFactory;
    private CsrfTokenManager $csrfTokenManager;

    public function __construct(
        Container       $container,
        LoggerInterface $logger
    )
    {
        $this->authService = $container->get(AuthService::class);
        $this->logger = $logger;
        $this->viewFactory = $container->get(ViewFactory::class);
        $this->csrfTokenManager = $container->get(CsrfTokenManager::class);
    }

    #[Route(path: '/register', name: 'auth.register', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Wenn der Benutzer bereits angemeldet ist, zur Startseite weiterleiten
        if ($this->authService->isLoggedIn()) {
            return Response::redirect('/');
        }

        return $this->viewFactory->render('auth/register', [
            'title' => 'Registrieren',
            'csrfToken' => $this->csrfTokenManager->getToken('register_form')
        ]);
    }
}