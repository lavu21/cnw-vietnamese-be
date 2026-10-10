<?php

namespace Tests\Feature;

use App\Enums\Audience;
use App\Enums\Tone;
use App\Exceptions\AnalysisFailedException;
use App\Libraries\Analysis\AnalyzerInterface;
use App\Libraries\Analysis\ErrorSanitizer;
use App\Libraries\RateLimiter;
use App\Libraries\TextNormalizer;
use App\Libraries\Utf16;
use App\Libraries\WritingService;
use App\Models\WritingErrorModel;
use App\Models\WritingModel;
use Config\Api;
use Config\Services;
use Tests\Support\ApiTestCase;

final class WritingApiTest extends ApiTestCase
{
    /** @return array<string, string> */
    private function validBody(array $override = []): array
    {
        return $override + ['text' => self::SAMPLE_TEXT, 'tone' => 'casual', 'audience' => 'child'];
    }

    public function testWritingsRequireAuthentication(): void
    {
        $this->get('api/v1/writings')->assertStatus(401);
        $this->postJson('api/v1/writings', $this->validBody())->assertStatus(401);
        $this->delete('api/v1/writings?confirm=true')->assertStatus(401);
    }

    public function testCreateReturnsAnalyzedWritingWithConsistentOffsets(): void
    {
        $headers = $this->authenticate();

        $result = $this->postJson('api/v1/writings', $this->validBody(), $headers);

        $result->assertStatus(201);
        $writing = $this->json($result);

        $this->assertSame('completed', $writing['status']);
        $this->assertSame(self::SAMPLE_TEXT, $writing['text']);
        $this->assertSame(TextNormalizer::wordCount(self::SAMPLE_TEXT), $writing['wordCount']);
        $this->assertGreaterThanOrEqual(3, count($writing['errors']));
        $this->assertSame(count($writing['errors']), $writing['summary']['total']);
        $this->assertSame(count($writing['errors']), $writing['summary']['pending']);
        $this->assertSame(['grammar', 'vocab', 'coherence'], array_keys($writing['summary']['byCategory']));

        $previousEnd = 0;

        foreach ($writing['errors'] as $error) {
            $this->assertSame('pending', $error['status']);
            $this->assertGreaterThanOrEqual($previousEnd, $error['startIndex']);
            $this->assertSame($error['original'], Utf16::slice($writing['text'], $error['startIndex'], $error['endIndex']));
            $previousEnd = $error['endIndex'];
        }

        $originals = array_column($writing['errors'], 'original');
        $this->assertContains('thứt dậy', $originals);
        $this->assertContains('bạn be', $originals);
    }

    public function testCreateNormalizesTextToNfc(): void
    {
        $headers    = $this->authenticate();
        $decomposed = \Normalizer::normalize(self::SAMPLE_TEXT, \Normalizer::FORM_D);

        $result = $this->postJson('api/v1/writings', $this->validBody(['text' => "  {$decomposed}  "]), $headers);

        $result->assertStatus(201);
        $this->assertSame(self::SAMPLE_TEXT, $this->json($result)['text']);
    }

    public function testCreateValidatesInput(): void
    {
        $headers = $this->authenticate();

        $short = $this->postJson('api/v1/writings', $this->validBody(['text' => 'quá ngắn']), $headers);
        $short->assertStatus(422);
        $this->assertSame('text', $this->json($short)['error']['details'][0]['field']);

        $this->postJson('api/v1/writings', $this->validBody(['text' => str_repeat('từ ', 5000)]), $headers)->assertStatus(422);

        $badEnums = $this->postJson('api/v1/writings', $this->validBody(['tone' => 'angry', 'audience' => 'alien']), $headers);
        $badEnums->assertStatus(422);
        $this->assertSame(['tone', 'audience'], array_column($this->json($badEnums)['error']['details'], 'field'));

        $missing = $this->postJson('api/v1/writings', [], $headers);
        $missing->assertStatus(422);
        $this->assertCount(3, $this->json($missing)['error']['details']);
    }

    public function testIdempotencyKeyPreventsDuplicates(): void
    {
        $headers = $this->authenticate() + ['Idempotency-Key' => 'abc-123'];

        $first = $this->postJson('api/v1/writings', $this->validBody(), $headers);
        $first->assertStatus(201);
        $firstId = $this->json($first)['id'];

        $second = $this->postJson('api/v1/writings', $this->validBody(), $headers);
        $second->assertStatus(200);
        $this->assertSame($firstId, $this->json($second)['id']);
        $this->assertSame(1, db_connect()->table('writings')->countAllResults());
    }

    public function testCreationIsRateLimitedPerUser(): void
    {
        $headers = $this->authenticate();

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('api/v1/writings', $this->validBody(), $headers)->assertStatus(201);
        }

        $result = $this->postJson('api/v1/writings', $this->validBody(), $headers);

        $result->assertStatus(429);
        $this->assertGreaterThan(0, (int) $result->response()->getHeaderLine('Retry-After'));
    }

    public function testOtherUsersCannotSeeOrChangeAWriting(): void
    {
        $owner = $this->authenticate('owner');
        $id    = $this->json($this->postJson('api/v1/writings', $this->validBody(), $owner))['id'];

        $this->withHeaders($owner)->get("api/v1/writings/{$id}")->assertStatus(200);

        $this->createUser('intruder');
        $intruder = $this->bearer($this->login('intruder')['accessToken']);

        $this->withHeaders($intruder)->get("api/v1/writings/{$id}")->assertStatus(404);
        $this->withHeaders($intruder)->post("api/v1/writings/{$id}/retry")->assertStatus(404);
        $this->withHeaders($intruder)->delete("api/v1/writings/{$id}")->assertStatus(204);
        $this->withHeaders($owner)->get("api/v1/writings/{$id}")->assertStatus(200);
        $this->withHeaders($owner)->get('api/v1/writings/not-a-uuid')->assertStatus(404);
    }

    public function testErrorDecisionIsPersistedAndUndoable(): void
    {
        $headers = $this->authenticate();
        $writing = $this->json($this->postJson('api/v1/writings', $this->validBody(), $headers));
        $id      = $writing['id'];
        $errorId = $writing['errors'][0]['id'];
        $url     = "api/v1/writings/{$id}/errors/{$errorId}";

        $accepted = $this->withHeaders($headers)->withBodyFormat('json')->patch($url, ['status' => 'accepted']);
        $accepted->assertStatus(200);
        $body = $this->json($accepted);
        $this->assertSame('accepted', $body['error']['status']);
        $this->assertSame(1, $body['summary']['accepted']);
        $this->assertSame($writing['summary']['total'] - 1, $body['summary']['pending']);

        $reopened = $this->json($this->withHeaders($headers)->get("api/v1/writings/{$id}"));
        $this->assertSame('accepted', $reopened['errors'][0]['status']);

        $undone = $this->withHeaders($headers)->withBodyFormat('json')->patch($url, ['status' => 'pending']);
        $this->assertSame(0, $this->json($undone)['summary']['accepted']);

        $this->withHeaders($headers)->withBodyFormat('json')->patch($url, ['status' => 'maybe'])->assertStatus(422);

        $this->withHeaders($headers)->withBodyFormat('json')
            ->patch("api/v1/writings/{$id}/errors/00000000-0000-4000-8000-000000000000", ['status' => 'accepted'])
            ->assertStatus(404);
    }

    public function testBatchSaveStoresAllDecisionsAtomically(): void
    {
        $headers = $this->authenticate();
        $writing = $this->json($this->postJson('api/v1/writings', $this->validBody(), $headers));
        $id      = $writing['id'];
        $url     = "api/v1/writings/{$id}/errors";
        [$first, $second, $third] = array_column($writing['errors'], 'id');

        $saved = $this->withHeaders($headers)->withBodyFormat('json')->patch($url, ['errors' => [
            ['id' => $first, 'status' => 'accepted'],
            ['id' => $second, 'status' => 'rejected'],
        ]]);

        $saved->assertStatus(200);
        $body = $this->json($saved);
        $this->assertSame(1, $body['summary']['accepted']);
        $this->assertSame(1, $body['summary']['rejected']);
        $this->assertSame(1, $body['summary']['pending']);
        $this->assertCount(3, $body['errors']);

        $reopened = $this->json($this->withHeaders($headers)->get("api/v1/writings/{$id}"));
        $this->assertSame(['accepted', 'rejected', 'pending'], array_column($reopened['errors'], 'status'));

        // One unknown id rejects the whole batch and changes nothing.
        $this->withHeaders($headers)->withBodyFormat('json')->patch($url, ['errors' => [
            ['id' => $third, 'status' => 'accepted'],
            ['id' => '00000000-0000-4000-8000-000000000000', 'status' => 'accepted'],
        ]])->assertStatus(404);
        $after = $this->json($this->withHeaders($headers)->get("api/v1/writings/{$id}"));
        $this->assertSame('pending', $after['errors'][2]['status']);

        foreach ([[], ['errors' => []], ['errors' => [['id' => 'x', 'status' => 'maybe']]]] as $invalid) {
            $this->withHeaders($headers)->withBodyFormat('json')->patch($url, $invalid)->assertStatus(422);
        }
    }

    public function testBatchSaveIsScopedToTheOwner(): void
    {
        $owner   = $this->authenticate('owner');
        $writing = $this->json($this->postJson('api/v1/writings', $this->validBody(), $owner));
        $this->createUser('intruder');
        $intruder = $this->bearer($this->login('intruder')['accessToken']);

        $this->withHeaders($intruder)->withBodyFormat('json')
            ->patch("api/v1/writings/{$writing['id']}/errors", ['errors' => [['id' => $writing['errors'][0]['id'], 'status' => 'accepted']]])
            ->assertStatus(404);
    }

    public function testRetryOnlyAppliesToFailedWritings(): void
    {
        $headers = $this->authenticate();
        $id      = $this->json($this->postJson('api/v1/writings', $this->validBody(), $headers))['id'];

        $result = $this->withHeaders($headers)->post("api/v1/writings/{$id}/retry");

        $result->assertStatus(409);
        $this->assertSame('CONFLICT', $this->json($result)['error']['code']);

        db_connect()->table('writings')->where('id', $id)->update(['status' => 'failed', 'failure_reason' => 'x']);
        db_connect()->table('writing_errors')->where('writing_id', $id)->delete();

        $retried = $this->withHeaders($headers)->post("api/v1/writings/{$id}/retry");
        $retried->assertStatus(200);
        $this->assertSame('completed', $this->json($retried)['status']);
        $this->assertNotEmpty($this->json($retried)['errors']);
    }

    public function testFailedAnalysisIsSavedAndReportedAsBadGateway(): void
    {
        $headers = $this->authenticate();

        $failing = new class () implements AnalyzerInterface {
            public function analyze(string $text, Tone $tone, Audience $audience): array
            {
                throw new AnalysisFailedException('upstream down');
            }
        };

        Services::injectMock('writingService', new WritingService(
            config(Api::class),
            new WritingModel(),
            new WritingErrorModel(),
            $failing,
            new ErrorSanitizer(),
            new RateLimiter(),
        ));

        $result = $this->postJson('api/v1/writings', $this->validBody(), $headers);

        $result->assertStatus(502);
        $error = $this->json($result)['error'];
        $this->assertSame('ANALYSIS_FAILED', $error['code']);

        $saved = $this->json($this->withHeaders($headers)->get('api/v1/writings/' . $error['writingId']));
        $this->assertSame('failed', $saved['status']);
        $this->assertNotNull($saved['failureReason']);
    }

    public function testHistoryListIsPaginatedFilteredAndScopedToTheUser(): void
    {
        $headers = $this->authenticate();
        $other   = $this->authenticate('other');

        foreach (['casual', 'professional', 'casual'] as $tone) {
            $this->postJson('api/v1/writings', $this->validBody(['tone' => $tone]), $headers)->assertStatus(201);
        }

        $this->postJson('api/v1/writings', $this->validBody(), $other)->assertStatus(201);

        $page1 = $this->json($this->withHeaders($headers)->get('api/v1/writings?limit=2'));
        $this->assertCount(2, $page1['items']);
        $this->assertSame(3, $page1['total']);
        $this->assertNotNull($page1['nextCursor']);
        $this->assertArrayNotHasKey('text', $page1['items'][0]);
        $this->assertArrayHasKey('textPreview', $page1['items'][0]);
        $this->assertGreaterThan(0, $page1['items'][0]['errorSummary']['total']);

        $page2 = $this->json($this->withHeaders($headers)->get('api/v1/writings?limit=2&cursor=' . urlencode($page1['nextCursor'])));
        $this->assertCount(1, $page2['items']);
        $this->assertNull($page2['nextCursor']);

        $ids = array_column([...$page1['items'], ...$page2['items']], 'id');
        $this->assertCount(3, array_unique($ids));

        $this->assertSame(1, $this->json($this->withHeaders($headers)->get('api/v1/writings?tone=professional'))['total']);
        $this->assertSame(3, $this->json($this->withHeaders($headers)->get('api/v1/writings?q=' . urlencode('bạn be')))['total']);
        $this->assertSame(0, $this->json($this->withHeaders($headers)->get('api/v1/writings?q=' . urlencode('100%_zzz')))['total']);

        $this->withHeaders($headers)->get('api/v1/writings?limit=1000')->assertStatus(422);
        $this->withHeaders($headers)->get('api/v1/writings?sort=text:asc')->assertStatus(422);
        $this->withHeaders($headers)->get('api/v1/writings?cursor=garbage')->assertStatus(422);
        $this->withHeaders($headers)->get("api/v1/writings?q=x'%20OR%201=1--")->assertStatus(200);
    }

    public function testDeleteIsIdempotentAndCascadesToErrors(): void
    {
        $headers = $this->authenticate();
        $id      = $this->json($this->postJson('api/v1/writings', $this->validBody(), $headers))['id'];

        $this->withHeaders($headers)->delete("api/v1/writings/{$id}")->assertStatus(204);
        $this->withHeaders($headers)->delete("api/v1/writings/{$id}")->assertStatus(204);
        $this->withHeaders($headers)->get("api/v1/writings/{$id}")->assertStatus(404);
        $this->assertSame(0, db_connect()->table('writing_errors')->countAllResults());
    }

    public function testDeleteAllRequiresConfirmation(): void
    {
        $headers = $this->authenticate();
        $this->postJson('api/v1/writings', $this->validBody(), $headers)->assertStatus(201);

        $this->withHeaders($headers)->delete('api/v1/writings')->assertStatus(400);
        $this->assertSame(1, db_connect()->table('writings')->countAllResults());

        $this->withHeaders($headers)->delete('api/v1/writings?confirm=true')->assertStatus(204);
        $this->assertSame(0, db_connect()->table('writings')->countAllResults());
    }
}
