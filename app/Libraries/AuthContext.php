<?php

namespace App\Libraries;

use App\Exceptions\ApiException;

/** Identity of the authenticated caller for the current request. Set by JwtAuthFilter. */
class AuthContext
{
    private ?string $userId    = null;
    private ?string $sessionId = null;

    public function set(string $userId, string $sessionId): void
    {
        $this->userId    = $userId;
        $this->sessionId = $sessionId;
    }

    public function userId(): string
    {
        return $this->userId ?? throw ApiException::unauthorized();
    }

    public function sessionId(): string
    {
        return $this->sessionId ?? throw ApiException::unauthorized();
    }
}
