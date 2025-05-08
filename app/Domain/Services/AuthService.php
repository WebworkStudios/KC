<?php


namespace App\Domain\Services;

use App\Domain\Repositories\UserRepository;
use Random\RandomException;
use Src\Log\LoggerInterface;
use Src\Session\SessionInterface;

class AuthService
{
    private UserRepository $userRepository;
    private LoggerInterface $logger;
    private SessionInterface $session;

    public function __construct(
        UserRepository   $userRepository,
        LoggerInterface  $logger,
        SessionInterface $session
    )
    {
        $this->userRepository = $userRepository;
        $this->logger = $logger;
        $this->session = $session;
    }

    public function login(string $email, string $password): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            $this->logger->notice('Login attempt with non-existent email', ['email' => $email]);
            return [
                'success' => false,
                'message' => 'Ungültige Anmeldedaten'
            ];
        }

        // Prüfen, ob das Konto gesperrt ist
        if ($user['account_status'] === 'locked') {
            $this->logger->notice('Login attempt on locked account', ['user_id' => $user['id']]);
            return [
                'success' => false,
                'message' => 'Dieses Konto wurde gesperrt'
            ];
        }

        // Prüfen, ob das Konto verifiziert ist
        if ($user['account_status'] === 'unverified') {
            $this->logger->notice('Login attempt on unverified account', ['user_id' => $user['id']]);
            return [
                'success' => false,
                'message' => 'Bitte aktiviere dein Konto über den Link in der Registrierungs-E-Mail'
            ];
        }

        // Password verification placeholder - actual hash verification skipped in this example
        if (!$this->verifyPassword($password, $user['password'] ?? '')) {
            $this->logger->notice('Failed login attempt (wrong password)', ['user_id' => $user['id']]);
            return [
                'success' => false,
                'message' => 'Ungültige Anmeldedaten'
            ];
        }

        // Login erfolgreich
        $this->userRepository->updateLastLogin($user['id']);

        // In Session speichern
        $this->session->set('user_id', $user['id']);
        $this->session->set('user_name', $user['username']);
        $this->session->set('user_role', $user['role']);

        $this->logger->info('User logged in successfully', ['user_id' => $user['id']]);

        return [
            'success' => true,
            'user' => $user
        ];
    }

    public function logout(): void
    {
        // User-ID für Logging holen, bevor die Session gelöscht wird
        $userId = $this->session->get('user_id');

        $this->session->remove('user_id');
        $this->session->remove('user_name');
        $this->session->remove('user_role');

        if ($userId) {
            $this->logger->info('User logged out', ['user_id' => $userId]);
        }
    }

    public function register(array $userData): array
    {
        // Prüfen, ob die E-Mail bereits existiert
        if ($this->userRepository->findByEmail($userData['email'])) {
            $this->logger->notice('Registration attempt with existing email', ['email' => $userData['email']]);
            return [
                'success' => false,
                'message' => 'Diese E-Mail-Adresse wird bereits verwendet'
            ];
        }

        // Prüfen, ob der Benutzername bereits existiert
        if ($this->userRepository->findByUsername($userData['username'])) {
            $this->logger->notice('Registration attempt with existing username', ['username' => $userData['username']]);
            return [
                'success' => false,
                'message' => 'Dieser Benutzername wird bereits verwendet'
            ];
        }

        // Standardwerte für neue Benutzer setzen
        $userData['account_status'] = 'unverified';
        $userData['role'] = 'user';
        $userData['registration_date'] = date('Y-m-d H:i:s');

        // Password hashing placeholder
        $userData['password'] = $this->hashPassword($userData['password']);

        // Benutzer erstellen
        $userId = $this->userRepository->create($userData);

        if (!$userId) {
            $this->logger->error('Failed to create user account', ['data' => $userData]);
            return [
                'success' => false,
                'message' => 'Bei der Registrierung ist ein Fehler aufgetreten'
            ];
        }

        // Aktivierungstoken erstellen
        $token = $this->generateToken();
        $this->userRepository->createToken($userId, $token, 'activation');

        $this->logger->info('User registered successfully', ['user_id' => $userId]);

        return [
            'success' => true,
            'user_id' => $userId,
            'activation_token' => $token
        ];
    }

    public function activateAccount(string $token): array
    {
        $tokenData = $this->userRepository->findToken($token, 'activation');

        if (!$tokenData) {
            $this->logger->notice('Invalid or expired activation token used', ['token' => $token]);
            return [
                'success' => false,
                'message' => 'Ungültiger oder abgelaufener Aktivierungslink'
            ];
        }

        // Token als verwendet markieren
        $this->userRepository->markTokenAsUsed($tokenData['id']);

        // Benutzer aktivieren
        $this->userRepository->activateUser($tokenData['user_id']);

        $this->logger->info('User account activated', ['user_id' => $tokenData['user_id']]);

        return [
            'success' => true,
            'user_id' => $tokenData['user_id']
        ];
    }

    public function isLoggedIn(): bool
    {
        return $this->session->has('user_id');
    }

    /**
     * @throws RandomException
     */
    private function generateToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    private function hashPassword(string $password): string
    {
        // In einer realen Anwendung sollte password_hash verwendet werden
        return password_hash($password, PASSWORD_DEFAULT);
    }

    private function verifyPassword(string $password, string $hashedPassword): bool
    {
        // In einer realen Anwendung sollte password_verify verwendet werden
        // Da wir das gehashte Passwort nicht aus dem vorhandenen Datenbankschema haben,
        // würden wir in der Praxis password_verify($password, $hashedPassword) verwenden
        // Für dieses Beispiel implementieren wir nur eine einfache Prüfung
        return !empty($hashedPassword) && password_verify($password, $hashedPassword);
    }
}