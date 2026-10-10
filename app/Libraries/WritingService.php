<?php

namespace App\Libraries;

use App\Enums\Audience;
use App\Enums\ErrorStatus;
use App\Enums\Tone;
use App\Enums\WritingStatus;
use App\Exceptions\AnalysisFailedException;
use App\Exceptions\ApiException;
use App\Libraries\Analysis\AnalyzerInterface;
use App\Libraries\Analysis\ErrorSanitizer;
use App\Models\WritingErrorModel;
use App\Models\WritingModel;
use Config\Api;
use Throwable;

class WritingService
{
    public function __construct(
        private readonly Api $config,
        private readonly WritingModel $writings,
        private readonly WritingErrorModel $writingErrors,
        private readonly AnalyzerInterface $analyzer,
        private readonly ErrorSanitizer $sanitizer,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * Creates a writing and analyzes it synchronously (docs/API.md, option A).
     *
     * @param string $text normalized text (see TextNormalizer)
     *
     * @return array{writing: array<string, mixed>, errors: list<array<string, mixed>>, replayed: bool}
     */
    public function create(string $userId, string $text, Tone $tone, Audience $audience, ?string $idempotencyKey): array
    {
        if ($idempotencyKey !== null && ($existing = $this->writings->findByIdempotencyKey($userId, $idempotencyKey)) !== null) {
            return ['writing' => $existing, 'errors' => $this->writingErrors->forWriting($existing['id']), 'replayed' => true];
        }

        $this->throttle($userId);

        $id = Uuid::v4();

        $this->writings->insert([
            'id'              => $id,
            'user_id'         => $userId,
            'text'            => $text,
            'tone'            => $tone->value,
            'audience'        => $audience->value,
            'word_count'      => TextNormalizer::wordCount($text),
            'status'          => WritingStatus::Processing->value,
            'idempotency_key' => $idempotencyKey,
        ]);

        return $this->analyze($this->writings->find($id)) + ['replayed' => false];
    }

    /** @return array{writing: array<string, mixed>, errors: list<array<string, mixed>>} */
    public function retry(string $userId, string $id): array
    {
        $writing = $this->findOwnedOrFail($userId, $id);

        if ($writing['status'] !== WritingStatus::Failed->value) {
            throw ApiException::conflict('Chỉ có thể thử lại bài viết có trạng thái thất bại.');
        }

        $this->throttle($userId);
        $this->writings->update($id, ['status' => WritingStatus::Processing->value, 'failure_reason' => null]);

        return $this->analyze($this->writings->find($id));
    }

    /** @return array{writing: array<string, mixed>, errors: list<array<string, mixed>>} */
    public function get(string $userId, string $id): array
    {
        $writing = $this->findOwnedOrFail($userId, $id);

        return ['writing' => $writing, 'errors' => $this->writingErrors->forWriting($id)];
    }

    /**
     * @param array{q?: string, tone?: string, audience?: string, from?: string, to?: string} $filters
     * @param array{createdAt: string, id: string}|null                                        $cursor
     *
     * @return array{items: list<array{writing: array<string, mixed>, summary: array{total: int, pending: int}|null}>, nextCursor: string|null, total: int}
     */
    public function list(string $userId, array $filters, int $limit, ?array $cursor, bool $newestFirst): array
    {
        $total = $this->writings->applyFilters($userId, $filters)->countAllResults();

        $query = $this->writings->applyFilters($userId, $filters);

        if ($cursor !== null) {
            $operator = $newestFirst ? '<' : '>';

            $query->groupStart()
                ->where("created_at {$operator}", $cursor['createdAt'])
                ->orGroupStart()
                ->where('created_at', $cursor['createdAt'])
                ->where("id {$operator}", $cursor['id'])
                ->groupEnd()
                ->groupEnd();
        }

        $direction = $newestFirst ? 'DESC' : 'ASC';
        $rows      = $query->orderBy('created_at', $direction)->orderBy('id', $direction)->findAll($limit + 1);

        $nextCursor = null;

        if (count($rows) > $limit) {
            $rows       = array_slice($rows, 0, $limit);
            $last       = $rows[$limit - 1];
            $nextCursor = self::encodeCursor($last['created_at'], $last['id']);
        }

        $summaries = $this->writingErrors->summariesFor(array_column($rows, 'id'));

        return [
            'items'      => array_map(static fn (array $w): array => ['writing' => $w, 'summary' => $summaries[$w['id']] ?? null], $rows),
            'nextCursor' => $nextCursor,
            'total'      => $total,
        ];
    }

    /** Idempotent: deleting a missing (or someone else's) writing succeeds silently. */
    public function delete(string $userId, string $id): void
    {
        $this->writings->where(['id' => $id, 'user_id' => $userId])->delete();
    }

    public function deleteAll(string $userId): void
    {
        $this->writings->where('user_id', $userId)->delete();
    }

    /**
     * @return array{error: array<string, mixed>, errors: list<array<string, mixed>>}
     */
    public function decide(string $userId, string $writingId, string $errorId, ErrorStatus $status): array
    {
        $this->findOwnedOrFail($userId, $writingId);

        $error = Uuid::isValid($errorId) ? $this->writingErrors->where(['id' => $errorId, 'writing_id' => $writingId])->first() : null;

        if ($error === null) {
            throw ApiException::notFound('Không tìm thấy lỗi này trong bài viết.');
        }

        if ($error['status'] !== $status->value) {
            $this->writingErrors->update($errorId, ['status' => $status->value]);
        }

        $errors = $this->writingErrors->forWriting($writingId);

        return ['error' => $this->writingErrors->find($errorId), 'errors' => $errors];
    }

    /**
     * Saves several accept/reject decisions at once (the "Lưu" button). All or nothing:
     * if any id is not an error of this writing, nothing is changed.
     *
     * @param array<string, ErrorStatus> $decisions error id => status
     *
     * @return list<array<string, mixed>> all errors of the writing after the update
     */
    public function decideMany(string $userId, string $writingId, array $decisions): array
    {
        $this->findOwnedOrFail($userId, $writingId);

        $current = array_column($this->writingErrors->forWriting($writingId), 'status', 'id');

        foreach (array_keys($decisions) as $errorId) {
            if (! isset($current[$errorId])) {
                throw ApiException::notFound('Không tìm thấy lỗi này trong bài viết.');
            }
        }

        $db = db_connect();
        $db->transStart();

        foreach ($decisions as $errorId => $status) {
            if ($current[$errorId] !== $status->value) {
                $this->writingErrors->update($errorId, ['status' => $status->value]);
            }
        }

        $db->transComplete();

        return $this->writingErrors->forWriting($writingId);
    }

    public static function decodeCursor(string $cursor): ?array
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($decoded) || ! is_string($decoded['c'] ?? null) || ! is_string($decoded['i'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $decoded['c']) !== 1 || ! Uuid::isValid($decoded['i'])) {
            return null;
        }

        return ['createdAt' => $decoded['c'], 'id' => $decoded['i']];
    }

    private static function encodeCursor(string $createdAt, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['c' => $createdAt, 'i' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * Runs the analyzer, stores the validated result, or marks the writing as failed.
     *
     * @param array<string, mixed> $writing
     *
     * @return array{writing: array<string, mixed>, errors: list<array<string, mixed>>}
     */
    private function analyze(array $writing): array
    {
        try {
            $detected = $this->analyzer->analyze($writing['text'], Tone::from($writing['tone']), Audience::from($writing['audience']));
        } catch (Throwable $e) {
            $this->writings->update($writing['id'], [
                'status'         => WritingStatus::Failed->value,
                'failure_reason' => 'Không thể kết nối tới máy chủ phân tích.',
            ]);

            if (! $e instanceof AnalysisFailedException) {
                log_message('error', 'Analyzer crashed: {message}', ['message' => $e->getMessage()]);
            }

            $timeout = $e instanceof AnalysisFailedException && $e->isTimeout();

            throw new ApiException(
                $timeout ? 504 : 502,
                'ANALYSIS_FAILED',
                'Không thể phân tích bài viết lúc này. Bạn có thể thử lại.',
                extra: ['writingId' => $writing['id']],
            );
        }

        $rows = array_map(static fn ($error): array => [
            'id'          => Uuid::v4(),
            'writing_id'  => $writing['id'],
            'category'    => $error->category,
            'status'      => ErrorStatus::Pending->value,
            'start_index' => $error->startIndex,
            'end_index'   => $error->endIndex,
            'original'    => $error->original,
            'suggestion'  => $error->suggestion,
            'explanation' => $error->explanation,
        ], $this->sanitizer->sanitize($writing['text'], $detected));

        $db = db_connect();
        $db->transStart();
        $this->writingErrors->where('writing_id', $writing['id'])->delete();

        if ($rows !== []) {
            $this->writingErrors->insertBatch($rows);
        }

        $this->writings->update($writing['id'], ['status' => WritingStatus::Completed->value, 'failure_reason' => null]);
        $db->transComplete();

        return ['writing' => $this->writings->find($writing['id']), 'errors' => $this->writingErrors->forWriting($writing['id'])];
    }

    private function throttle(string $userId): void
    {
        $key = 'analysis:' . $userId;

        if ($this->limiter->tooMany($key, $this->config->analysesPerHour)) {
            throw ApiException::tooManyRequests('RATE_LIMITED', 'Bạn đã gửi quá nhiều bài trong một giờ. Vui lòng thử lại sau.', $this->limiter->retryAfter($key));
        }

        $this->limiter->hit($key, 3600);
    }

    /** @return array<string, mixed> */
    private function findOwnedOrFail(string $userId, string $id): array
    {
        $writing = Uuid::isValid($id) ? $this->writings->findOwned($id, $userId) : null;

        return $writing ?? throw ApiException::notFound('Không tìm thấy bài viết.');
    }
}
