<?php

namespace Config;

use App\Libraries\Analysis\AnalyzerInterface;
use App\Libraries\Analysis\ErrorSanitizer;
use App\Libraries\Analysis\MockAnalyzer;
use App\Libraries\AuthContext;
use App\Libraries\AuthService;
use App\Libraries\JwtService;
use App\Libraries\PasswordHasher;
use App\Libraries\RateLimiter;
use App\Libraries\WritingService;
use App\Models\RefreshTokenModel;
use App\Models\UserModel;
use App\Models\WritingErrorModel;
use App\Models\WritingModel;
use CodeIgniter\Config\BaseService;
use InvalidArgumentException;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 */
class Services extends BaseService
{
    public static function authContext(bool $getShared = true): AuthContext
    {
        if ($getShared) {
            return static::getSharedInstance('authContext');
        }

        return new AuthContext();
    }

    public static function jwt(bool $getShared = true): JwtService
    {
        if ($getShared) {
            return static::getSharedInstance('jwt');
        }

        return new JwtService(config(Api::class));
    }

    public static function rateLimiter(bool $getShared = true): RateLimiter
    {
        if ($getShared) {
            return static::getSharedInstance('rateLimiter');
        }

        return new RateLimiter();
    }

    public static function authService(bool $getShared = true): AuthService
    {
        if ($getShared) {
            return static::getSharedInstance('authService');
        }

        return new AuthService(
            config(Api::class),
            new UserModel(),
            new RefreshTokenModel(),
            new PasswordHasher(),
            static::jwt(),
            static::rateLimiter(),
        );
    }

    /** Select the implementation with `api.analyzer` in `.env`. */
    public static function analyzer(bool $getShared = true): AnalyzerInterface
    {
        if ($getShared) {
            return static::getSharedInstance('analyzer');
        }

        return match (config(Api::class)->analyzer) {
            'mock'  => new MockAnalyzer(),
            default => throw new InvalidArgumentException('Unknown api.analyzer value.'),
        };
    }

    public static function writingService(bool $getShared = true): WritingService
    {
        if ($getShared) {
            return static::getSharedInstance('writingService');
        }

        return new WritingService(
            config(Api::class),
            new WritingModel(),
            new WritingErrorModel(),
            static::analyzer(),
            new ErrorSanitizer(),
            static::rateLimiter(),
        );
    }
}
