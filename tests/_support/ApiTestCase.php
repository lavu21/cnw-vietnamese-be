<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * Base class for HTTP-level tests: fresh in-memory database, cache and a seeded user per test.
 */
abstract class ApiTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $refresh   = true;
    protected $namespace = 'App';

    protected const PASSWORD = 'correct horse battery';

    protected const SAMPLE_TEXT = 'Hôm nay tôi đi học sớm vì tôi thứt dậy sớm. Tôi nghỉ rằng nếu đi sớm thì sẽ không bị kẹt xe, nhưng thật ra hôm nay đường lại đông hơn mọi khi. Trên đường đi, tôi gặp bạn be cũ và chúng tôi nói chuyện rất vui.';

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(true);
        cache()->clean();
    }

    /** @return string user id */
    protected function createUser(string $username = 'demo'): string
    {
        return service('authService')->createUser($username, self::PASSWORD);
    }

    /** @return array{accessToken: string, refreshToken: string, expiresIn: int, user: array<string, string>} */
    protected function login(string $username = 'demo'): array
    {
        $result = $this->postJson('api/v1/auth/login', ['username' => $username, 'password' => self::PASSWORD]);
        $result->assertStatus(200);

        return json_decode($result->getJSON(), true);
    }

    /** @return array<string, string> */
    protected function bearer(string $accessToken): array
    {
        return ['Authorization' => 'Bearer ' . $accessToken];
    }

    /** @param array<string, mixed> $body */
    protected function postJson(string $path, array $body, array $headers = []): TestResponse
    {
        return $this->withHeaders($headers)->withBodyFormat('json')->post($path, $body);
    }

    /** Creates a user, logs in, and returns the Authorization header. */
    protected function authenticate(string $username = 'demo'): array
    {
        $this->createUser($username);

        return $this->bearer($this->login($username)['accessToken']);
    }

    /** @return array<string, mixed> */
    protected function json(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }
}
