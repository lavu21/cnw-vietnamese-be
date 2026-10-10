<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

final class AuthApiTest extends ApiTestCase
{
    public function testLoginReturnsTokensAndUser(): void
    {
        $this->createUser();

        $result = $this->postJson('api/v1/auth/login', ['username' => ' demo ', 'password' => self::PASSWORD]);

        $result->assertStatus(200);
        $body = $this->json($result);
        $this->assertSame(900, $body['expiresIn']);
        $this->assertSame('demo', $body['user']['username']);
        $this->assertNotEmpty($body['accessToken']);
        $this->assertNotEmpty($body['refreshToken']);
        $this->assertNotSame('', $result->response()->getHeaderLine('X-Request-Id'));
        $this->assertSame('nosniff', $result->response()->getHeaderLine('X-Content-Type-Options'));
    }

    public function testPasswordsAreHashedAtRest(): void
    {
        $this->createUser();

        $hash = db_connect()->table('users')->get()->getRowArray()['password_hash'];

        $this->assertNotSame(self::PASSWORD, $hash);
        $this->assertTrue(password_verify(self::PASSWORD, $hash));
    }

    public function testWrongPasswordAndUnknownUserGetTheSameResponse(): void
    {
        $this->createUser();

        $wrongPassword = $this->postJson('api/v1/auth/login', ['username' => 'demo', 'password' => 'nope']);
        $wrongPassword->assertStatus(401);
        $wrongBody = $this->json($wrongPassword);

        $unknownUser = $this->postJson('api/v1/auth/login', ['username' => 'ghost', 'password' => 'nope']);
        $unknownUser->assertStatus(401);

        $this->assertSame($wrongBody, $this->json($unknownUser));
        $this->assertSame('INVALID_CREDENTIALS', $wrongBody['error']['code']);
    }
    public function testLoginValidatesRequiredFields(): void
    {
        $result = $this->postJson('api/v1/auth/login', ['username' => '']);

        $result->assertStatus(422);
        $error = $this->json($result)['error'];
        $this->assertSame('VALIDATION_FAILED', $error['code']);
        $this->assertSame(['username', 'password'], array_column($error['details'], 'field'));
    }

    public function testLoginRejectsMalformedJson(): void
    {
        $result = $this->withHeaders(['Content-Type' => 'application/json'])->withBody('{not json')->post('api/v1/auth/login');

        $result->assertStatus(400);
        $this->assertSame('BAD_REQUEST', $this->json($result)['error']['code']);
    }

    public function testLoginIsRateLimitedAfterRepeatedFailures(): void
    {
        $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('api/v1/auth/login', ['username' => 'demo', 'password' => 'bad'])->assertStatus(401);
        }

        $result = $this->postJson('api/v1/auth/login', ['username' => 'demo', 'password' => self::PASSWORD]);

        $result->assertStatus(429);
        $this->assertSame('TOO_MANY_ATTEMPTS', $this->json($result)['error']['code']);
        $this->assertGreaterThan(0, (int) $result->response()->getHeaderLine('Retry-After'));
    }

    public function testMeRequiresAValidToken(): void
    {
        $this->get('api/v1/auth/me')->assertStatus(401);
        $this->withHeaders($this->bearer('not.a.jwt'))->get('api/v1/auth/me')->assertStatus(401);
    }

    public function testMeReturnsCurrentUser(): void
    {
        $headers = $this->authenticate();

        $result = $this->withHeaders($headers)->get('api/v1/auth/me');

        $result->assertStatus(200);
        $this->assertSame('demo', $this->json($result)['username']);
    }

    public function testTokenSignedWithAnotherSecretIsRejected(): void
    {
        $this->createUser();
        $forged = \Firebase\JWT\JWT::encode(
            ['iss' => 'vietnamese-practice', 'aud' => 'vietnamese-practice-web', 'sub' => 'x', 'sid' => 'y', 'exp' => time() + 60],
            str_repeat('a', 32),
            'HS256',
        );

        $this->withHeaders($this->bearer($forged))->get('api/v1/auth/me')->assertStatus(401);
    }

    public function testRefreshRotatesTokensAndDetectsReuse(): void
    {
        $this->createUser();
        $first = $this->login();

        $second = $this->postJson('api/v1/auth/refresh', ['refreshToken' => $first['refreshToken']]);
        $second->assertStatus(200);
        $secondBody = $this->json($second);
        $this->assertNotSame($first['refreshToken'], $secondBody['refreshToken']);

        // Reusing the rotated token fails and revokes the whole session.
        $this->postJson('api/v1/auth/refresh', ['refreshToken' => $first['refreshToken']])->assertStatus(401);
        $this->postJson('api/v1/auth/refresh', ['refreshToken' => $secondBody['refreshToken']])->assertStatus(401);
        $this->withHeaders($this->bearer($secondBody['accessToken']))->get('api/v1/auth/me')->assertStatus(401);
    }

    public function testRefreshRejectsUnknownToken(): void
    {
        $result = $this->postJson('api/v1/auth/refresh', ['refreshToken' => 'unknown']);

        $result->assertStatus(401);
        $this->assertSame('INVALID_REFRESH_TOKEN', $this->json($result)['error']['code']);
    }

    public function testLogoutRevokesTheSession(): void
    {
        $this->createUser();
        $session = $this->login();
        $headers = $this->bearer($session['accessToken']);

        $this->withHeaders($headers)->post('api/v1/auth/logout')->assertStatus(204);
        $this->withHeaders($headers)->get('api/v1/auth/me')->assertStatus(401);
        $this->postJson('api/v1/auth/refresh', ['refreshToken' => $session['refreshToken']])->assertStatus(401);
    }

    public function testCorsPreflightAllowsConfiguredOrigin(): void
    {
        $result = $this->withHeaders([
            'Origin'                         => 'http://localhost:3000',
            'Access-Control-Request-Method'  => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('api/v1/writings');

        $result->assertStatus(204);
        $this->assertSame('http://localhost:3000', $result->response()->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsPreflightRejectsOtherOrigins(): void
    {
        $result = $this->withHeaders([
            'Origin'                        => 'http://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('api/v1/writings');

        $this->assertNotSame('http://evil.example', $result->response()->getHeaderLine('Access-Control-Allow-Origin'));
    }
    public function testHealthEndpointIsPublic(): void
    {
        $result = $this->get('health');

        $result->assertStatus(200);
        $this->assertSame('ok', $this->json($result)['status']);
    }
}
