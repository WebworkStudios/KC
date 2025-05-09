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
use Src\Http\Middleware\CsrfMiddleware;

class LoginAction
{
    private AuthService $authService;
    private LoggerInterface $logger;
    private ViewFactory $viewFactory;
    private CsrfTokenManager $csrfTokenManager;
    private SessionInterface $session;
    private CsrfMiddleware $csrfMiddleware;

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

        // Get the CsrfMiddleware which has helper methods for CSRF token generation
        $this->csrfMiddleware = $container->get(CsrfMiddleware::class);
    }

    #[Route(path: '/login', name: 'auth.login', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Wenn der Benutzer bereits angemeldet ist, zur Startseite weiterleiten
        if ($this->authService->isLoggedIn()) {
            $this->logger->info('Bereits angemeldeter Benutzer versuchte, die Login-Seite aufzurufen');
            return Response::redirect('/');
        }

        // Erfolgs- und Fehlermeldungen aus der Session abrufen
        $success = $this->session->getFlash('success');
        $error = $this->session->getFlash('error');
        $errors = $this->session->getFlash('errors') ?? [];

        // Fehler für spezifische Felder extrahieren
        $email_error = $errors['email'] ?? null;
        $password_error = $errors['password'] ?? null;

        // Alte Eingabewerte aus der Session für Formular-Persistenz abrufen
        $old_input = $this->session->getFlash('old_input') ?? [];
        $old_email = $old_input['email'] ?? '';

        try {
            // Generate CSRF token HTML field
            $csrfTokenField = $this->csrfMiddleware->generateTokenField('login_form');

            $this->logger->debug('Login-Seite aufgerufen', [
                'has_errors' => !empty($errors),
                'has_old_input' => !empty($old_input)
            ]);
        } catch (\Throwable $e) {
            // Fehler beim Generieren des CSRF-Tokens protokollieren
            $this->logger->error('Fehler beim Generieren des CSRF-Tokens', [
                'error' => $e->getMessage()
            ]);

            // Fallback CSRF field
            $csrfTokenField = '<input type="hidden" name="_csrf" value="fallback_token_' . bin2hex(random_bytes(16)) . '">';
        }

        // View mit allen benötigten Daten rendern
        return $this->viewFactory->render('auth/login', [
            'title' => 'Anmelden',
            'csrfTokenField' => $csrfTokenField,  // Send the complete HTML field instead of just the token
            'success' => $success,
            'error' => $error,
            'email_error' => $email_error,
            'password_error' => $password_error,
            'old_email' => $old_email
        ]);
    }
}