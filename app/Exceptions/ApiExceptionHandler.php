<?php

namespace App\Exceptions;

use App\Filters\ApiHeadersFilter;
use App\Libraries\ApiResponse;
use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Cors;
use CodeIgniter\HTTP\Exceptions\HTTPException as RequestHttpException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Exceptions as ExceptionsConfig;
use Throwable;

/**
 * Renders every failure under `/api/*` in the standard error envelope and delegates
 * everything else to the framework handler.
 */
class ApiExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(private readonly ExceptionsConfig $config)
    {
    }

    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        if (! $request instanceof IncomingRequest || ! str_starts_with(ltrim($request->getPath(), '/'), 'api/')) {
            (new ExceptionHandler($this->config))->handle($exception, $request, $response, $statusCode, $exitCode);

            return;
        }

        if ($exception instanceof ApiException) {
            ApiResponse::error($response, $exception);
        } elseif ($exception instanceof PageNotFoundException) {
            ApiResponse::errorBody($response, 404, 'NOT_FOUND', 'Không tìm thấy đường dẫn.');
        } elseif ($exception instanceof RequestHttpException && $statusCode >= 400 && $statusCode < 500) {
            ApiResponse::errorBody($response, $statusCode, 'BAD_REQUEST', 'Yêu cầu không hợp lệ.');
        } else {
            ApiResponse::errorBody($response, 500, 'INTERNAL_ERROR', 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.');
        }

        ApiHeadersFilter::applyHeaders($request, $response);
        Cors::factory()->addResponseHeaders($request, $response);

        $response->send();

        if (ENVIRONMENT !== 'testing') {
            exit($exitCode);
        }
    }
}
