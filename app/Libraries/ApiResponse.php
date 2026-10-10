<?php

namespace App\Libraries;

use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;

final class ApiResponse
{
    public static function error(ResponseInterface $response, ApiException $e): ResponseInterface
    {
        return self::errorBody($response, $e->getCode(), $e->errorCode, $e->getMessage(), $e->details, $e->extra, $e->headers);
    }

    /**
     * @param list<array{field: string, message: string}> $details
     * @param array<string, mixed>                        $extra
     * @param array<string, string>                       $headers
     */
    public static function errorBody(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message,
        array $details = [],
        array $extra = [],
        array $headers = [],
    ): ResponseInterface {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response->setStatusCode($status)->setJSON(['error' => $error + $extra]);
    }
}
