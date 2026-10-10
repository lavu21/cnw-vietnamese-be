<?php

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Libraries\ApiResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base class for JSON API controllers: converts ApiException into the standard error response.
 */
abstract class BaseApiController extends BaseController
{
    /** @param mixed ...$params */
    public function _remap(string $method, ...$params): ResponseInterface
    {
        try {
            return $this->{$method}(...$params);
        } catch (ApiException $e) {
            return ApiResponse::error($this->response, $e);
        }
    }

    /** @return array<string, mixed> */
    protected function jsonBody(): array
    {
        $raw = trim((string) $this->request->getBody());

        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw ApiException::badRequest('Nội dung gửi lên phải là một đối tượng JSON hợp lệ.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $group rule group name in Config\Validation
     */
    protected function validateGroup(array $data, string $group): void
    {
        $validation = service('validation');

        if (! $validation->run($data, $group)) {
            throw ApiException::validation($validation->getErrors());
        }
    }

    /** @param array<string, mixed> $data */
    protected function respond(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($data);
    }

    protected function noContent(): ResponseInterface
    {
        return $this->response->setStatusCode(204)->setBody('');
    }
}
