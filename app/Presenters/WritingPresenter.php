<?php

namespace App\Presenters;

use App\Enums\ErrorCategory;
use App\Enums\ErrorStatus;

/**
 * The "view" layer of the JSON API: turns database rows into the response shapes in docs/API.md.
 */
final class WritingPresenter
{
    private const PREVIEW_LENGTH = 200;

    /**
     * @param array<string, mixed>       $writing
     * @param list<array<string, mixed>> $errors
     *
     * @return array<string, mixed>
     */
    public static function resource(array $writing, array $errors): array
    {
        return [
            'id'            => $writing['id'],
            'text'          => $writing['text'],
            'tone'          => $writing['tone'],
            'audience'      => $writing['audience'],
            'wordCount'     => (int) $writing['word_count'],
            'status'        => $writing['status'],
            'failureReason' => $writing['failure_reason'],
            'errors'        => array_map(self::error(...), $errors),
            'summary'       => self::summary($errors),
            'createdAt'     => self::iso($writing['created_at']),
            'updatedAt'     => self::iso($writing['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed>                $writing
     * @param array{total: int, pending: int}|null $summary
     *
     * @return array<string, mixed>
     */
    public static function listItem(array $writing, ?array $summary): array
    {
        return [
            'id'           => $writing['id'],
            'textPreview'  => mb_substr($writing['text'], 0, self::PREVIEW_LENGTH),
            'wordCount'    => (int) $writing['word_count'],
            'tone'         => $writing['tone'],
            'audience'     => $writing['audience'],
            'status'       => $writing['status'],
            'errorSummary' => $summary ?? ['total' => 0, 'pending' => 0],
            'createdAt'    => self::iso($writing['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $error
     *
     * @return array<string, mixed>
     */
    public static function error(array $error): array
    {
        return [
            'id'          => $error['id'],
            'category'    => $error['category'],
            'status'      => $error['status'],
            'startIndex'  => (int) $error['start_index'],
            'endIndex'    => (int) $error['end_index'],
            'original'    => $error['original'],
            'suggestion'  => $error['suggestion'],
            'explanation' => $error['explanation'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $errors
     *
     * @return array{total: int, pending: int, accepted: int, rejected: int, byCategory: array<string, int>}
     */
    public static function summary(array $errors): array
    {
        $summary = [
            'total'      => count($errors),
            'pending'    => 0,
            'accepted'   => 0,
            'rejected'   => 0,
            'byCategory' => array_fill_keys(ErrorCategory::values(), 0),
        ];

        foreach ($errors as $error) {
            if (ErrorStatus::tryFrom($error['status']) !== null) {
                $summary[$error['status']]++;
            }

            if (isset($summary['byCategory'][$error['category']])) {
                $summary['byCategory'][$error['category']]++;
            }
        }

        return $summary;
    }

    private static function iso(?string $utcDateTime): ?string
    {
        return $utcDateTime === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($utcDateTime . ' UTC'));
    }
}
