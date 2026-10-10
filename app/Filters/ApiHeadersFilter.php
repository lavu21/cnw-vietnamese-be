<?php

namespace App\Filters;

use App\Exceptions\ApiException;
use App\Libraries\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Api;

/**
 * Global filter: request ID, body size limit and security headers for every response.
 */
class ApiHeadersFilter implements FilterInterface
{
    private static ?string $requestId = null;

    public static function requestId(): ?string
    {
        return self::$requestId;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $incoming        = $request->getHeaderLine('X-Request-Id');
        self::$requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1
            ? $incoming
            : bin2hex(random_bytes(8));

        $length = (int) $request->getHeaderLine('Content-Length');

        if ($length > config(Api::class)->maxBodyBytes) {
            return ApiResponse::error(
                service('response'),
                new ApiException(413, 'PAYLOAD_TOO_LARGE', 'Nội dung gửi lên quá lớn.'),
            );
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        self::applyHeaders($request, $response);

        return null;
    }

    public static function applyHeaders(RequestInterface $request, ResponseInterface $response): void
    {
        $response->setHeader('X-Request-Id', self::$requestId ?? bin2hex(random_bytes(8)));
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $response->setHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->setHeader('Cache-Control', 'no-store');

        if ($request instanceof IncomingRequest && $request->isSecure()) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
