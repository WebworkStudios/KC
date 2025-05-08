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
use Src\Session\SessionInterface;

class LoginAction
{
    private AuthService $authService;
    private LoggerInterface $logger;
    private ViewFactory $viewFactory;
    private CsrfTokenManager $csrfTokenManager;
    private SessionInterface $session;

    public function __construct(
        Container       $container,
        LoggerInterface $logger
    )
    {
        $this->authService = $container->get(AuthService::class);
        $this->logger = $logger;
        $this->viewFactory = $container->get(ViewFactory::class);
        $this->csrfTokenManager = $container->get(CsrfTokenManager::class);
        $this->session = $container->get(SessionInterface::class);
    }

    #[Route(path: '/login', name: 'auth.login', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Wenn der Benutzer bereits angemeldet ist, zur Startseite weiterleiten
        if ($this->authService->isLoggedIn()) {
            return Response::redirect('/');
        }

        // Fehlermeldungen aus der Session abrufen
        $error = $this->session->get('error');
        $errors = $this->session->get('errors') ?? [];
        $email_error = $errors['email'] ?? null;
        $password_error = $errors['password'] ?? null;

        // Alte Eingabewerte aus der Session abrufen
        $old_input = $this->session->get('old_input') ?? [];
        $old_email = $old_input['email'] ?? '';

        // Flash-Daten aus der Session entfernen (nachdem wir sie gelesen haben)
        if ($this->session->has('error')) {
            $this->session->remove('error');
        }
        if ($this->session->has('errors')) {
            $this->session->remove('errors');
        }
        if ($this->session->has('old_input')) {
            $this->session->remove('old_input');
        }

        return $this->viewFactory->render('auth/login', [
            'title' => 'Anmelden',
            'csrfToken' => $this->csrfTokenManager->getToken('login_form'),
            'error' => $error,
            'email_error' => $email_error,
            'password_error' => $password_error,
            'old_email' => $old_email
        ]);
    }
}