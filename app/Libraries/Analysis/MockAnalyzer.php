<?php

namespace App\Libraries\Analysis;

use App\Enums\Audience;
use App\Enums\ErrorCategory;
use App\Enums\Tone;
use App\Libraries\Utf16;
use Normalizer;

/**
 * Dictionary and heuristic based analyzer. Stands in for a real AI service
 * (it is the server-side port of the frontend's `lib/mock-ai.ts`).
 */
class MockAnalyzer implements AnalyzerInterface
{
    private const MAX_COHERENCE_PICKS = 8;

    private const WORD_BOUNDARY_BEFORE = '(?<![\p{L}\p{N}])';
    private const WORD_BOUNDARY_AFTER  = '(?![\p{L}\p{N}])';

    /** @var list<array{phrase: string, suggestion: string, explanation: string, category: ErrorCategory}> */
    private array $dictionary;

    /** @var array<string, array{suggestion: string, explanation: string}> */
    private array $curatedCoherence;

    public function __construct()
    {
        $this->dictionary = array_map(
            static fn (array $e): array => [
                'phrase'      => (string) Normalizer::normalize($e[0], Normalizer::FORM_C),
                'suggestion'  => $e[1],
                'explanation' => $e[2],
                'category'    => $e[3],
            ],
            [
                ['thứt dậy', 'thức dậy', '"thứt dậy" sai chính tả → nên viết "thức dậy".', ErrorCategory::Grammar],
                ['bạn be', 'bạn bè', '"bạn be" thiếu dấu, từ đúng là "bạn bè".', ErrorCategory::Vocab],
                ['dc', 'được', '"dc" là viết tắt không chuẩn trong văn viết, nên viết đầy đủ "được".', ErrorCategory::Grammar],
                ['ko', 'không', '"ko" là viết tắt không chuẩn trong văn viết, nên viết đầy đủ "không".', ErrorCategory::Grammar],
                ['coi phim', 'xem phim', '"coi phim" là cách nói khẩu ngữ, văn viết nên dùng "xem phim".', ErrorCategory::Vocab],
                ['hong', 'không', '"hong" là cách viết khẩu ngữ, nên viết đầy đủ "không".', ErrorCategory::Vocab],
                ['chia sẽ', 'chia sẻ', '"chia sẽ" dùng sai từ — "sẽ" là thì tương lai, từ đúng ở đây là "chia sẻ".', ErrorCategory::Grammar],
                ['sát nhập', 'sáp nhập', '"sát nhập" là cách viết sai phổ biến, từ đúng là "sáp nhập".', ErrorCategory::Grammar],
                ['cọ sát', 'cọ xát', '"cọ sát" viết sai chính tả, từ đúng là "cọ xát".', ErrorCategory::Vocab],
                ['sáng lạn', 'xán lạn', '"sáng lạn" viết sai, từ đúng là "xán lạn" (tương lai xán lạn).', ErrorCategory::Vocab],
                ['tựu chung', 'tựu trung', '"tựu chung" viết sai, từ đúng là "tựu trung" (tựu trung lại).', ErrorCategory::Grammar],
                ['nghành', 'ngành', '"nghành" thừa chữ "h", từ đúng là "ngành".', ErrorCategory::Grammar],
                ['sử lý', 'xử lý', '"sử lý" viết sai, từ đúng là "xử lý".', ErrorCategory::Grammar],
            ],
        );

        $this->curatedCoherence = [
            'Tôi nghỉ rằng nếu đi sớm thì sẽ không bị kẹt xe, nhưng thật ra hôm nay đường lại đông hơn mọi khi.' => [
                'suggestion'  => 'Tôi nghĩ đi sớm sẽ tránh được kẹt xe, nhưng hôm nay đường lại đông hơn thường lệ.',
                'explanation' => 'Câu chưa liên kết rõ với ý trước đó và dùng từ chưa chuẩn ("nghỉ" → "nghĩ"). Đề xuất diễn đạt mạch lạc, súc tích hơn.',
            ],
        ];
    }

    public function analyze(string $text, Tone $tone, Audience $audience): array
    {
        /** @var list<array{0: int, 1: int}> $claimed UTF-16 ranges */
        $claimed = [];
        $errors  = [];

        foreach ($this->dictionary as $entry) {
            $pattern = '/' . self::WORD_BOUNDARY_BEFORE . preg_quote($entry['phrase'], '/') . self::WORD_BOUNDARY_AFTER . '/u';

            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as [$matched, $byteOffset]) {
                $range = $this->range($text, $byteOffset, $matched);

                if ($this->overlapsAny($claimed, $range)) {
                    continue;
                }

                $claimed[] = $range;
                $errors[]  = new DetectedError(
                    $entry['category']->value,
                    $range[0],
                    $range[1],
                    $matched,
                    $entry['suggestion'],
                    $entry['explanation'],
                );
            }
        }

        return [...$errors, ...$this->coherenceErrors($text, $claimed)];
    }

    /**
     * @param list<array{0: int, 1: int}> $claimed
     *
     * @return list<DetectedError>
     */
    private function coherenceErrors(string $text, array $claimed): array
    {
        preg_match_all('/[^.!?]+[.!?]*/u', $text, $matches, PREG_OFFSET_CAPTURE);

        $errors = [];

        foreach ($matches[0] as [$raw, $byteOffset]) {
            if (count($errors) >= self::MAX_COHERENCE_PICKS) {
                break;
            }

            $sentence = trim($raw);

            if ($sentence === '') {
                continue;
            }

            $byteOffset += strlen($raw) - strlen(ltrim($raw));
            $range = $this->range($text, $byteOffset, $sentence);

            if ($this->overlapsAny($claimed, $range)) {
                continue;
            }

            $words = preg_split('/\s+/u', $sentence) ?: [];
            $parts = preg_split('/(?<!\p{L})nhưng(?!\p{L})/iu', $sentence) ?: [];

            $hasButConnector = count($parts) > 1 && trim($parts[0]) !== '';
            $isLong          = count($words) > 14;

            if (! $hasButConnector && ! $isLong) {
                continue;
            }

            if (isset($this->curatedCoherence[$sentence])) {
                ['suggestion' => $suggestion, 'explanation' => $explanation] = $this->curatedCoherence[$sentence];
            } elseif ($hasButConnector) {
                $head        = rtrim(trim($parts[0]), ',');
                $tail        = trim(implode(' nhưng ', array_slice($parts, 1)));
                $suggestion  = "{$head}, nhưng {$tail}";
                $explanation = 'Câu chưa liên kết rõ với ý trước đó. Đề xuất diễn đạt mạch lạc hơn.';
            } else {
                $mid         = (int) ceil(count($words) / 2);
                $suggestion  = implode(' ', array_slice($words, 0, $mid)) . '. ' . implode(' ', array_slice($words, $mid));
                $explanation = 'Câu khá dài, nên tách thành hai câu ngắn để mạch lạc hơn.';
            }

            $claimed[] = $range;
            $errors[]  = new DetectedError(ErrorCategory::Coherence->value, $range[0], $range[1], $sentence, trim($suggestion), $explanation);
        }

        return $errors;
    }

    /** @return array{0: int, 1: int} */
    private function range(string $text, int $byteOffset, string $matched): array
    {
        $start = Utf16::length(substr($text, 0, $byteOffset));

        return [$start, $start + Utf16::length($matched)];
    }

    /**
     * @param list<array{0: int, 1: int}> $claimed
     * @param array{0: int, 1: int}       $range
     */
    private function overlapsAny(array $claimed, array $range): bool
    {
        foreach ($claimed as $other) {
            if ($other[0] < $range[1] && $range[0] < $other[1]) {
                return true;
            }
        }

        return false;
    }
}

