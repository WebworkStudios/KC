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

class RegisterProcessAction
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

    #[Route(path: '/register', name: 'auth.register.process', methods: ['POST'], middleware: [CsrfMiddleware::class])]
    public function __invoke(Request $request): Response
    {
        $username = $request->post('username');
        $email = $request->post('email');
        $password = $request->post('password');
        $passwordConfirm = $request->post('password_confirm');
        $termsAccepted = (bool)$request->post('terms_accepted', 0);
        $newsletter = (bool)$request->post('newsletter', 0);

        // Validierung
        $errors = [];

        if (empty($username)) {
            $errors['username'] = 'Bitte gib einen Benutzernamen ein';
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $errors['username'] = 'Der Benutzername muss zwischen 3 und 50 Zeichen lang sein';
        }

        if (empty($email)) {
            $errors['email'] = 'Bitte gib deine E-Mail-Adresse ein';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Bitte gib eine gültige E-Mail-Adresse ein';
        }

        if (empty($password)) {
            $errors['password'] = 'Bitte gib ein Passwort ein';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Das Passwort muss mindestens 8 Zeichen lang sein';
        }

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Die Passwörter stimmen nicht überein';
        }

        if (!$termsAccepted) {
            $errors['terms_accepted'] = 'Du musst die AGB akzeptieren';
        }

        // Wenn Validierungsfehler aufgetreten sind, zurück zum Registrierungsformular
        if (!empty($errors)) {
            // Fehler in Flash-Message speichern
            $this->session->flash('errors', $errors);
            $this->session->flash('old_input', [
                'username' => $username,
                'email' => $email,
                'newsletter' => $newsletter
            ]);

            return Response::redirect('/register');
        }

        // Benutzer registrieren
        $result = $this->authService->register([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'terms_accepted' => $termsAccepted ? 1 : 0,
            'newsletter' => $newsletter ? 1 : 0
        ]);

        if (!$result['success']) {
            // Fehlermeldung anzeigen
            $this->session->flash('error', $result['message']);
            $this->session->flash('old_input', [
                'username' => $username,
                'email' => $email,
                'newsletter' => $newsletter
            ]);

            return Response::redirect('/register');
        }

        // Im echten System würde hier eine E-Mail mit dem Aktivierungslink versendet werden
        // Für dieses Beispiel leiten wir einfach zur Bestätigungsseite weiter
        $this->session->flash('success', 'Dein Konto wurde erfolgreich erstellt. Bitte überprüfe deine E-Mails, um dein Konto zu aktivieren.');

        // In einer echten Anwendung würden wir hier den Aktivierungslink generieren
        // und per E-Mail versenden, anstatt ihn in der Session zu speichern
        $this->session->flash('activation_link', '/activate/' . $result['activation_token']);

        return Response::redirect('/register/success');
    }
}