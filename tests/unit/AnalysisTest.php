<?php

namespace Tests\Unit;

use App\Enums\Audience;
use App\Enums\Tone;
use App\Libraries\Analysis\DetectedError;
use App\Libraries\Analysis\ErrorSanitizer;
use App\Libraries\Analysis\MockAnalyzer;
use App\Libraries\TextNormalizer;
use App\Libraries\Utf16;
use CodeIgniter\Test\CIUnitTestCase;

final class AnalysisTest extends CIUnitTestCase
{
    public function testUtf16LengthCountsSurrogatePairsAsTwoUnits(): void
    {
        $this->assertSame(6, Utf16::length('Việt😀'));
        $this->assertSame('Việt', Utf16::slice('Việt😀', 0, 4));
        $this->assertSame('😀', Utf16::slice('Việt😀', 4, 6));
    }

    public function testTextNormalizerProducesTrimmedNfcAndCountsWords(): void
    {
        $decomposed = \Normalizer::normalize('  tiếng Việt  ', \Normalizer::FORM_D);

        $this->assertSame('tiếng Việt', TextNormalizer::normalize($decomposed));
        $this->assertSame('', TextNormalizer::normalize("\xC3\x28"));
        $this->assertSame(0, TextNormalizer::wordCount("  \n "));
        $this->assertSame(3, TextNormalizer::wordCount("một  hai\nba"));
    }

    public function testMockAnalyzerUsesUtf16OffsetsAndWordBoundaries(): void
    {
        $text = '😀 Tôi thứt dậy sớm và ko đi học, bạn be dc nghỉ. Chữ kodak không bị đánh dấu.';

        $errors = (new MockAnalyzer())->analyze($text, Tone::Casual, Audience::Child);

        foreach ($errors as $error) {
            $this->assertSame($error->original, Utf16::slice($text, $error->startIndex, $error->endIndex));
        }

        $originals = array_map(static fn (DetectedError $e): string => $e->original, $errors);
        $this->assertContains('thứt dậy', $originals);
        $this->assertContains('ko', $originals);
        $this->assertContains('dc', $originals);
        $this->assertNotContains('kodak', $originals);
        $this->assertSame(7, $errors[0]->startIndex);
    }

    public function testSanitizerDropsInvalidOverlappingAndMismatchedErrors(): void
    {
        $text = 'Tôi thứt dậy sớm hôm nay';

        $good      = new DetectedError('grammar', 4, 12, 'thứt dậy', 'thức dậy', 'giải thích');
        $overlap   = new DetectedError('vocab', 8, 16, 'dậy sớm', 'x', 'y');
        $mismatch  = new DetectedError('grammar', 0, 3, 'sai', 'x', 'y');
        $outOfRange = new DetectedError('grammar', 20, 99, 'hôm nay', 'x', 'y');
        $badCategory = new DetectedError('style', 17, 24, 'hôm nay', 'x', 'y');
        $noSuggestion = new DetectedError('grammar', 13, 16, 'sớm', '', 'y');

        $result = (new ErrorSanitizer())->sanitize($text, [$overlap, $mismatch, $outOfRange, $badCategory, $noSuggestion, $good]);

        $this->assertSame([$good], $result);
    }
}
