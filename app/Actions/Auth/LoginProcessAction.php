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
            // Fehler in Flash-Message speichern
            $this->session->flash('errors', $errors);
            $this->session->flash('old_input', ['email' => $email]);

            return Response::redirect('/login');
        }

        // Login-Versuch
        $result = $this->authService->login($email, $password);

        if (!$result['success']) {
            // Fehlermeldung anzeigen
            $this->session->flash('error', $result['message']);
            $this->session->flash('old_input', ['email' => $email]);

            return Response::redirect('/login');
        }

        // Erfolgreiche Anmeldung, Weiterleitung zum Dashboard
        $this->session->flash('success', 'Du wurdest erfolgreich angemeldet');
        return Response::redirect('/dashboard');
    }
}