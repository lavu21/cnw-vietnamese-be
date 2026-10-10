<?php

namespace App\Exceptions;

use CodeIgniter\Exceptions\HTTPExceptionInterface;
use RuntimeException;

/**
 * An expected API failure. Rendered as `{ "error": { "code", "message", "details"? } }`.
 * The exception code is the HTTP status.
 */
class ApiException extends RuntimeException implements HTTPExceptionInterface
{
    /**
     * @param list<array{field: string, message: string}> $details
     * @param array<string, string>                       $headers
     * @param array<string, mixed>                        $extra   merged into the `error` object
     */
    public function __construct(
        int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $message = 'Yêu cầu không hợp lệ.'): self
    {
        return new self(400, 'BAD_REQUEST', $message);
    }

    public static function unauthorized(string $message = 'Thiếu hoặc sai thông tin xác thực.', string $code = 'UNAUTHORIZED'): self
    {
        return new self(401, $code, $message, headers: ['WWW-Authenticate' => 'Bearer']);
    }

    public static function notFound(string $message = 'Không tìm thấy dữ liệu.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'CONFLICT', $message);
    }

    /** @param array<string, string> $errors field => message */
    public static function validation(array $errors): self
    {
        $details = [];

        foreach ($errors as $field => $message) {
            $details[] = ['field' => (string) $field, 'message' => $message];
        }

        return new self(422, 'VALIDATION_FAILED', 'Dữ liệu không hợp lệ.', $details);
    }

    public static function tooManyRequests(string $code, string $message, int $retryAfter): self
    {
        return new self(429, $code, $message, headers: ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}
