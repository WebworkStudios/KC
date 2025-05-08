<?php

namespace App\Actions\Auth;

use App\Domain\Services\AuthService;
use Src\Container\Container;
use Src\Http\Request;
use Src\Http\Response;
use Src\Http\Route;
use Src\Http\Middleware\CsrfMiddleware;
use Src\Log\LoggerInterface;
use Src\Session\SessionInterface;

class LoginProcessAction
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

    #[Route(path: '/login', name: 'auth.login.process', methods: ['POST'], middleware: [CsrfMiddleware::class])]
    public function __invoke(Request $request): Response
    {
        $email = $request->post('email');
        $password = $request->post('password');
        $remember = (bool)$request->post('remember', false);

        // Validierung
        $errors = [];

        if (empty($email)) {
            $errors['email'] = 'Bitte gib deine E-Mail-Adresse ein';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein';
        }

        if (empty($password)) {
            $errors['password'] = 'Bitte gib dein Passwort ein';
        }

        // Wenn Validierungsfehler aufgetreten sind, zurück zum Login-Formular
        if (!empty($errors)) {
            $this->logger->notice('Login-Versuch mit Validierungsfehlern', [
                'email' => $email,
                'errors' => array_keys($errors)
            ]);

            // Fehler in Flash-Message speichern
            $this->session->flash('errors', $errors);
            $this->session->flash('old_input', ['email' => $email]);

            return Response::redirect('/login');
        }

        // Login-Versuch
        $result = $this->authService->login($email, $password, $remember);

        if (!$result['success']) {
            $this->logger->notice('Fehlgeschlagener Login-Versuch', [
                'email' => $email,
                'error' => $result['message']
            ]);

            // Fehlermeldung anzeigen
            $this->session->flash('error', $result['message']);
            $this->session->flash('old_input', ['email' => $email]);

            return Response::redirect('/login');
        }

        $this->logger->info('Erfolgreicher Login', [
            'user_id' => $result['user']->id ?? null,
            'email' => $email,
            'remember' => $remember
        ]);

        // Erfolgreiche Anmeldung, Weiterleitung zum Dashboard
        $this->session->flash('success', 'Du wurdest erfolgreich angemeldet');

        // Umleitung zur vorherigen Seite, falls verfügbar, ansonsten zum Dashboard
        $redirectTo = $this->session->get('redirect_after_login', '/dashboard');
        $this->session->remove('redirect_after_login');

        return Response::redirect($redirectTo);
    }
}