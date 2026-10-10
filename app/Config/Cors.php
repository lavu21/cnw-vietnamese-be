<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Cross-Origin Resource Sharing for the React frontend.
 * Allowed origins come from `api.corsAllowedOrigins` (comma separated) in `.env`.
 * The API authenticates with a Bearer header, so credentials (cookies) are not enabled.
 */
class Cors extends BaseConfig
{
    /** @var array<string, mixed> */
    public array $default = [
        'allowedOrigins'         => [],
        'allowedOriginsPatterns' => [],
        'supportsCredentials'    => false,
        'allowedHeaders'         => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Request-Id'],
        'exposedHeaders'         => ['X-Request-Id', 'Retry-After'],
        'allowedMethods'         => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
        'maxAge'                 => 7200,
    ];

    public function __construct()
    {
        parent::__construct();

        $origins = array_filter(array_map('trim', explode(',', config(Api::class)->corsAllowedOrigins)));

        $this->default['allowedOrigins'] = array_values($origins);
    }
}
