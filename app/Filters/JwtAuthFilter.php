<?php

namespace App\Filters;

use App\Exceptions\ApiException;
use App\Libraries\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a valid `Authorization: Bearer <access token>` whose session has not been revoked.
 */
class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) !== 1) {
            return ApiResponse::error(service('response'), ApiException::unauthorized());
        }

        $claims = service('jwt')->verify($m[1]);

        if ($claims === null || ! service('authService')->isSessionActive($claims['sid'])) {
            return ApiResponse::error(service('response'), ApiException::unauthorized('Phiên đăng nhập không hợp lệ hoặc đã hết hạn.'));
        }

        service('authContext')->set($claims['sub'], $claims['sid']);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
