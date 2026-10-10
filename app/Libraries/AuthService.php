<?php

namespace App\Libraries;

use App\Exceptions\ApiException;
use App\Models\RefreshTokenModel;
use App\Models\UserModel;
use Config\Api;

class AuthService
{
    public function __construct(
        private readonly Api $config,
        private readonly UserModel $users,
        private readonly RefreshTokenModel $refreshTokens,
        private readonly PasswordHasher $hasher,
        private readonly JwtService $jwt,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** @return array{accessToken: string, refreshToken: string, expiresIn: int, user: array{id: string, username: string}} */
    public function login(string $username, string $password, string $ip): array
    {
        $userKey = 'login:' . mb_strtolower($username) . '|' . $ip;
        $ipKey   = 'login-ip:' . $ip;

        if ($this->limiter->tooMany($userKey, $this->config->loginMaxAttempts)
            || $this->limiter->tooMany($ipKey, $this->config->loginIpMaxAttempts)) {
            throw ApiException::tooManyRequests(
                'TOO_MANY_ATTEMPTS',
                'Bạn đã thử đăng nhập quá nhiều lần. Vui lòng thử lại sau.',
                max($this->limiter->retryAfter($userKey), $this->limiter->retryAfter($ipKey)),
            );
        }

        $user = $this->users->findByUsername($username);

        if ($user !== null) {
            $valid = $this->hasher->verify($password, $user['password_hash']);
        } else {
            // Hash anyway so response time does not reveal which usernames exist.
            $this->hasher->hash($password);
            $valid = false;
        }

        if (! $valid) {
            $this->limiter->hit($userKey, $this->config->loginWindow);
            $this->limiter->hit($ipKey, $this->config->loginWindow);

            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Tên đăng nhập hoặc mật khẩu không đúng.');
        }

        $this->limiter->clear($userKey);

        if ($this->hasher->needsRehash($user['password_hash'])) {
            $this->users->update($user['id'], ['password_hash' => $this->hasher->hash($password)]);
        }

        return $this->startSession($user, Uuid::v4());
    }

    /** Rotates the refresh token. Reusing an already rotated token revokes the whole session. */
    public function refresh(string $refreshToken): array
    {
        $invalid = static fn (): ApiException => ApiException::unauthorized('Refresh token không hợp lệ hoặc đã hết hạn.', 'INVALID_REFRESH_TOKEN');

        $stored = $this->refreshTokens->findByHash(hash('sha256', $refreshToken));

        if ($stored === null) {
            throw $invalid();
        }

        if ($stored['used_at'] !== null || $stored['revoked_at'] !== null) {
            $this->refreshTokens->revokeFamily($stored['family_id']);

            throw $invalid();
        }

        if (strtotime($stored['expires_at'] . ' UTC') <= time() || ! $this->refreshTokens->consume($stored['id'])) {
            throw $invalid();
        }

        $user = $this->users->find($stored['user_id']);

        if ($user === null) {
            throw $invalid();
        }

        return $this->startSession($user, $stored['family_id']);
    }

    public function logout(string $sessionId): void
    {
        $this->refreshTokens->revokeFamily($sessionId);
    }

    public function isSessionActive(string $sessionId): bool
    {
        return $this->refreshTokens->isFamilyActive($sessionId);
    }

    /** @return array{id: string, username: string}|null */
    public function findUser(string $id): ?array
    {
        $user = $this->users->find($id);

        return $user === null ? null : ['id' => $user['id'], 'username' => $user['username']];
    }

    /** @return array<string, mixed>|null */
    public function findUserByUsername(string $username): ?array
    {
        return $this->users->findByUsername($username);
    }

    public function createUser(string $username, string $password): string
    {
        $id = Uuid::v4();
        $this->users->insert(['id' => $id, 'username' => $username, 'password_hash' => $this->hasher->hash($password)]);

        return $id;
    }

    /** @param array<string, mixed> $user */
    private function startSession(array $user, string $familyId): array
    {
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->refreshTokens->insert([
            'id'         => Uuid::v4(),
            'user_id'    => $user['id'],
            'family_id'  => $familyId,
            'token_hash' => hash('sha256', $refreshToken),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->refreshTokenTtl),
        ]);

        $access = $this->jwt->issue($user['id'], $familyId);

        return [
            'accessToken'  => $access['token'],
            'refreshToken' => $refreshToken,
            'expiresIn'    => $access['expiresIn'],
            'user'         => ['id' => $user['id'], 'username' => $user['username']],
        ];
    }
}
