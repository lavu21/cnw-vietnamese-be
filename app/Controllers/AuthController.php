<?php

namespace App\Controllers;

use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;

class AuthController extends BaseApiController
{
    public function login(): ResponseInterface
    {
        $data = $this->jsonBody();

        // The password is never trimmed; the username is.
        if (isset($data['username']) && is_string($data['username'])) {
            $data['username'] = trim($data['username']);
        }

        $this->validateGroup($data, 'login');

        $session = service('authService')->login($data['username'], $data['password'], $this->request->getIPAddress());

        return $this->respond($session);
    }

    public function refresh(): ResponseInterface
    {
        $data = $this->jsonBody();
        $this->validateGroup($data, 'refresh');

        return $this->respond(service('authService')->refresh($data['refreshToken']));
    }

    public function logout(): ResponseInterface
    {
        service('authService')->logout(service('authContext')->sessionId());

        return $this->noContent();
    }

    public function me(): ResponseInterface
    {
        $user = service('authService')->findUser(service('authContext')->userId());

        if ($user === null) {
            throw ApiException::unauthorized();
        }

        return $this->respond($user);
    }
}
