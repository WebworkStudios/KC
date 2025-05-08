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

class RegisterAction
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

    #[Route(path: '/register', name: 'auth.register', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Wenn der Benutzer bereits angemeldet ist, zur Startseite weiterleiten
        if ($this->authService->isLoggedIn()) {
            $this->logger->info('Bereits angemeldeter Benutzer versuchte, die Registrierungsseite aufzurufen');
            return Response::redirect('/');
        }

        // Erfolgs- und Fehlermeldungen aus der Session abrufen
        $success = $this->session->getFlash('success');
        $error = $this->session->getFlash('error');
        $errors = $this->session->getFlash('errors') ?? [];

        // Fehler für spezifische Felder extrahieren
        $username_error = $errors['username'] ?? null;
        $email_error = $errors['email'] ?? null;
        $password_error = $errors['password'] ?? null;
        $password_confirm_error = $errors['password_confirm'] ?? null;
        $terms_accepted_error = $errors['terms_accepted'] ?? null;

        // Alte Eingabewerte aus der Session für Formular-Persistenz abrufen
        $old_input = $this->session->getFlash('old_input') ?? [];
        $old_username = $old_input['username'] ?? '';
        $old_email = $old_input['email'] ?? '';
        $old_newsletter = $old_input['newsletter'] ?? false;

        try {
            // Generate CSRF token HTML field
            $csrfTokenField = $this->csrfMiddleware->generateTokenField('register_form');

            $this->logger->debug('Registrierungsseite aufgerufen', [
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
        return $this->viewFactory->render('auth/register', [
            'title' => 'Registrieren',
            'csrfTokenField' => $csrfTokenField,  // Send the complete HTML field instead of just the token
            'success' => $success,
            'error' => $error,
            'username_error' => $username_error,
            'email_error' => $email_error,
            'password_error' => $password_error,
            'password_confirm_error' => $password_confirm_error,
            'terms_accepted_error' => $terms_accepted_error,
            'old_username' => $old_username,
            'old_email' => $old_email,
            'old_newsletter' => $old_newsletter
        ]);
    }
}