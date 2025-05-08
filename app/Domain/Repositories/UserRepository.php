<?php


namespace App\Domain\Repositories;

use Src\Database\QueryBuilder;
use Src\Database\DatabaseFactory;
use Src\Log\LoggerInterface;

class UserRepository
{
    private QueryBuilder $query;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->query = DatabaseFactory::createQueryBuilder('kickerscup');
        $this->logger = $logger;
    }

    public function findById(int $id): ?array
    {
        return $this->query->table('users')
            ->where('id', $id)
            ->first();
    }

    public function findByEmail(string $email): ?array
    {
        return $this->query->table('users')
            ->where('email', $email)
            ->first();
    }

    public function findByUsername(string $username): ?array
    {
        return $this->query->table('users')
            ->where('username', $username)
            ->first();
    }

    public function create(array $userData): int|string
    {
        return $this->query->table('users')
            ->insert($userData);
    }

    public function updateLastLogin(int $userId): int
    {
        return $this->query->table('users')
            ->where('id', $userId)
            ->update([
                'last_login' => date('Y-m-d H:i:s')
            ]);
    }

    public function createToken(int $userId, string $token, string $type, int $expiresInHours = 24): int|string
    {
        $expiresAt = new \DateTime();
        $expiresAt->modify("+{$expiresInHours} hours");

        return $this->query->table('user_tokens')
            ->insert([
                'user_id' => $userId,
                'token' => $token,
                'type' => $type,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s')
            ]);
    }

    public function findToken(string $token, string $type): ?array
    {
        return $this->query->table('user_tokens')
            ->where('token', $token)
            ->where('type', $type)
            ->where('used', 0)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->first();
    }

    public function markTokenAsUsed(int $tokenId): int
    {
        return $this->query->table('user_tokens')
            ->where('id', $tokenId)
            ->update([
                'used' => 1
            ]);
    }

    public function activateUser(int $userId): int
    {
        return $this->query->table('users')
            ->where('id', $userId)
            ->update([
                'account_status' => 'active'
            ]);
    }
}