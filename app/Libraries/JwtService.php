<?php

namespace App\Libraries;

use Config\Api;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/** Issues and verifies short-lived HS256 access tokens. */
class JwtService
{
    private const ALGORITHM = 'HS256';

    public function __construct(private readonly Api $config)
    {
        if (strlen($this->config->jwtSecret) < 32) {
            throw new RuntimeException('api.jwtSecret must be set to at least 32 bytes (see .env).');
        }
    }

    /** @return array{token: string, expiresIn: int} */
    public function issue(string $userId, string $sessionId): array
    {
        $now = time();

        $token = JWT::encode([
            'iss' => $this->config->jwtIssuer,
            'aud' => $this->config->jwtAudience,
            'sub' => $userId,
            'sid' => $sessionId,
            'jti' => Uuid::v4(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->config->accessTokenTtl,
        ], $this->config->jwtSecret, self::ALGORITHM);

        return ['token' => $token, 'expiresIn' => $this->config->accessTokenTtl];
    }

    /** @return array{sub: string, sid: string}|null null when the token is invalid or expired */
    public function verify(string $token): ?array
    {
        try {
            JWT::$leeway = 5;
            $claims      = (array) JWT::decode($token, new Key($this->config->jwtSecret, self::ALGORITHM));
        } catch (Throwable) {
            return null;
        }

        if (($claims['iss'] ?? null) !== $this->config->jwtIssuer || ($claims['aud'] ?? null) !== $this->config->jwtAudience) {
            return null;
        }

        if (! is_string($claims['sub'] ?? null) || ! is_string($claims['sid'] ?? null)) {
            return null;
        }

        return ['sub' => $claims['sub'], 'sid' => $claims['sid']];
    }
}
