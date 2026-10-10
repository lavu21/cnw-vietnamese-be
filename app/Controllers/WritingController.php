<?php

namespace App\Controllers;

use App\Enums\Audience;
use App\Enums\ErrorStatus;
use App\Enums\Tone;
use App\Exceptions\ApiException;
use App\Libraries\TextNormalizer;
use App\Libraries\Uuid;
use App\Libraries\WritingService;
use App\Presenters\WritingPresenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Api;

class WritingController extends BaseApiController
{
    private const MAX_BATCH = 500;

    public function index(): ResponseInterface
    {
        $api    = config(Api::class);
        $params = $this->request->getGet();
        $errors = [];

        $limit = $api->defaultPageSize;

        if (isset($params['limit'])) {
            $limit = filter_var($params['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $api->maxPageSize]]);

            if ($limit === false) {
                $errors['limit'] = "limit phải là số nguyên từ 1 đến {$api->maxPageSize}.";
            }
        }

        $cursor = null;

        if (isset($params['cursor']) && $params['cursor'] !== '') {
            $cursor = is_string($params['cursor']) ? WritingService::decodeCursor($params['cursor']) : null;

            if ($cursor === null) {
                $errors['cursor'] = 'cursor không hợp lệ.';
            }
        }

        $sort = $params['sort'] ?? 'createdAt:desc';

        if (! in_array($sort, ['createdAt:desc', 'createdAt:asc'], true)) {
            $errors['sort'] = 'sort chỉ hỗ trợ createdAt:desc hoặc createdAt:asc.';
        }

        $filters = [];

        foreach (['tone' => Tone::values(), 'audience' => Audience::values()] as $field => $allowed) {
            if (isset($params[$field]) && $params[$field] !== '') {
                if (! is_string($params[$field]) || ! in_array($params[$field], $allowed, true)) {
                    $errors[$field] = "{$field} không hợp lệ.";
                } else {
                    $filters[$field] = $params[$field];
                }
            }
        }

        if (isset($params['q']) && $params['q'] !== '') {
            if (! is_string($params['q']) || strlen($params['q']) > 200) {
                $errors['q'] = 'q không hợp lệ (tối đa 200 ký tự).';
            } else {
                $filters['q'] = TextNormalizer::normalize($params['q']);
            }
        }

        foreach (['from' => false, 'to' => true] as $field => $endOfDay) {
            if (! isset($params[$field]) || $params[$field] === '') {
                continue;
            }

            $date = is_string($params[$field]) ? $this->parseDate($params[$field], $endOfDay) : null;

            if ($date === null) {
                $errors[$field] = "{$field} phải là ngày theo chuẩn ISO-8601.";
            } else {
                $filters[$field] = $date;
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $result = service('writingService')->list(service('authContext')->userId(), $filters, $limit, $cursor, $sort === 'createdAt:desc');

        return $this->respond([
            'items'      => array_map(static fn (array $i): array => WritingPresenter::listItem($i['writing'], $i['summary']), $result['items']),
            'nextCursor' => $result['nextCursor'],
            'total'      => $result['total'],
        ]);
    }

    public function create(): ResponseInterface
    {
        $data = $this->jsonBody();

        if (isset($data['text']) && is_string($data['text'])) {
            $data['text'] = TextNormalizer::normalize($data['text']);
        }

        $this->validateGroup($data, 'createWriting');

        $result = service('writingService')->create(
            service('authContext')->userId(),
            $data['text'],
            Tone::from($data['tone']),
            Audience::from($data['audience']),
            $this->idempotencyKey(),
        );

        return $this->respond(WritingPresenter::resource($result['writing'], $result['errors']), $result['replayed'] ? 200 : 201);
    }

    public function show(string $id): ResponseInterface
    {
        $result = service('writingService')->get(service('authContext')->userId(), $id);

        return $this->respond(WritingPresenter::resource($result['writing'], $result['errors']));
    }

    public function retry(string $id): ResponseInterface
    {
        $result = service('writingService')->retry(service('authContext')->userId(), $id);

        return $this->respond(WritingPresenter::resource($result['writing'], $result['errors']));
    }

    public function updateError(string $id, string $errorId): ResponseInterface
    {
        $data = $this->jsonBody();
        $this->validateGroup($data, 'errorDecision');

        $result = service('writingService')->decide(
            service('authContext')->userId(),
            $id,
            $errorId,
            ErrorStatus::from($data['status']),
        );

        return $this->respond([
            'error'   => WritingPresenter::error($result['error']),
            'summary' => WritingPresenter::summary($result['errors']),
        ]);
    }

    /** Body: `{ "errors": [{ "id": "...", "status": "accepted|rejected|pending" }] }`. */
    public function updateErrors(string $id): ResponseInterface
    {
        $items = $this->jsonBody()['errors'] ?? null;

        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_BATCH) {
            throw ApiException::validation(['errors' => 'errors phải là danh sách từ 1 đến ' . self::MAX_BATCH . ' phần tử.']);
        }

        $problems  = [];
        $decisions = [];

        foreach ($items as $i => $item) {
            $errorId = is_array($item) ? ($item['id'] ?? null) : null;
            $status  = is_array($item) && is_string($item['status'] ?? null) ? ErrorStatus::tryFrom($item['status']) : null;

            if (! is_string($errorId) || ! Uuid::isValid($errorId)) {
                $problems["errors.{$i}.id"] = 'id không hợp lệ.';
            }

            if ($status === null) {
                $problems["errors.{$i}.status"] = 'Trạng thái không hợp lệ.';
            }

            if ($problems === []) {
                $decisions[$errorId] = $status;
            }
        }

        if ($problems !== []) {
            throw ApiException::validation($problems);
        }

        $errors = service('writingService')->decideMany(service('authContext')->userId(), $id, $decisions);

        return $this->respond([
            'errors'  => array_map(WritingPresenter::error(...), $errors),
            'summary' => WritingPresenter::summary($errors),
        ]);
    }

    public function delete(string $id): ResponseInterface
    {
        service('writingService')->delete(service('authContext')->userId(), $id);

        return $this->noContent();
    }

    public function deleteAll(): ResponseInterface
    {
        if ($this->request->getGet('confirm') !== 'true') {
            throw new ApiException(400, 'CONFIRMATION_REQUIRED', 'Cần thêm ?confirm=true để xóa tất cả bài viết.');
        }

        service('writingService')->deleteAll(service('authContext')->userId());

        return $this->noContent();
    }

    private function idempotencyKey(): ?string
    {
        $key = trim($this->request->getHeaderLine('Idempotency-Key'));

        if ($key === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $key) !== 1) {
            throw ApiException::badRequest('Idempotency-Key không hợp lệ.');
        }

        return $key;
    }

    /** Converts an ISO-8601 date or date-time to a UTC `Y-m-d H:i:s` string (a bare date `to` means end of day). */
    private function parseDate(string $value, bool $endOfDay): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $value .= $endOfDay ? 'T23:59:59Z' : 'T00:00:00Z';
        }

        $timestamp = preg_match('/^\d{4}-\d{2}-\d{2}T[\d:.]+(Z|[+-]\d{2}:?\d{2})$/', $value) === 1 ? strtotime($value) : false;

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }
}
