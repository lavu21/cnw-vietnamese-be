<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Application settings for the JSON API. Override any value in `.env`, e.g. `api.jwtSecret = ...`.
 */
class Api extends BaseConfig
{
    /** HMAC key for access tokens. Must be at least 32 bytes, e.g. `php -r "echo bin2hex(random_bytes(32));"`. */
    public string $jwtSecret = '';

    public string $jwtIssuer   = 'vietnamese-practice';
    public string $jwtAudience = 'vietnamese-practice-web';

    /** Access token lifetime in seconds (returned as `expiresIn`). */
    public int $accessTokenTtl = 900;

    /** Refresh token lifetime in seconds (30 days). */
    public int $refreshTokenTtl = 2592000;

    /** Failed logins allowed per username+IP inside `loginWindow` seconds. */
    public int $loginMaxAttempts = 5;

    /** Failed logins allowed per IP inside `loginWindow` seconds. */
    public int $loginIpMaxAttempts = 30;

    public int $loginWindow = 900;

    /** Analyses (create + retry) allowed per user per hour. */
    public int $analysesPerHour = 20;

    /** Maximum request body size in bytes. */
    public int $maxBodyBytes = 1048576;

    public int $minWords      = 20;
    public int $maxTextLength = 10000;

    public int $defaultPageSize = 20;
    public int $maxPageSize     = 100;

    /** Analyzer implementation: `mock` (dictionary based). Add more in Config\Services::analyzer(). */
    public string $analyzer = 'mock';

    /** Comma separated list of allowed frontend origins. */
    public string $corsAllowedOrigins = 'http://localhost:3000';

    public function __construct()
    {
        parent::__construct();

        if (ENVIRONMENT === 'testing' && $this->jwtSecret === '') {
            $this->jwtSecret = 'testing-only-secret-testing-only-secret';
        }
    }
}
