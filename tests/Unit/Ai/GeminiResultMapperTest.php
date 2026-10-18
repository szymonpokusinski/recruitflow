<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiResultSchema;
use App\Services\Ai\DTO\AiItem;
use App\Services\Ai\DTO\AiRequest;
use App\Services\Ai\DTO\AiResult;
use App\Services\Ai\Gemini\GeminiPromptBuilder;
use App\Services\Ai\Gemini\GeminiResultMapper;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ai\GeminiResponses;
use Tests\TestCase;

class GeminiResultMapperTest extends TestCase
{
    private function map(array $response, bool $grounding = false): AiResult
    {
        return app(GeminiResultMapper::class)->map($response, 'gemini', 'jobs', CarbonImmutable::parse('2026-10-14T10:00:00Z'), 'gemini-flash-latest', $grounding);
    }

    public function test_loose_json_uses_the_common_mapper_with_zero_null_original_text_and_redaction(): void
    {
        $row = ['position' => 'Księgowy', 'link' => 'https://example.org/job/1', 'amount' => '0',
            'currency' => 'pln', 'confidence' => '0%', 'id' => 42,
            'location' => ['city' => 'Warszawa', 'country' => 'Polska'],
            'description' => ["Wiersz 1\nWiersz 2", 'Wiersz 3'],
            'metadata' => ['company' => 'Firma', 'api_key' => 'not-for-output', 'gemini' => ['interaction_id' => 'spoof']],
        ];
        $result = $this->map(GeminiResponses::completed("```json\n".json_encode(['offers' => [$row, ['title' => 'Kierowca']]])."\n```"));
        $this->assertInstanceOf(AiResult::class, $result);
        $this->assertInstanceOf(AiItem::class, $result->items[0]);
        $this->assertSame('gemini', $result->provider);
        $this->assertSame('gemini-3.8-flash', $result->model);
        $this->assertSame('v1_gemini-controlled-test', $result->requestId);
        $this->assertSame(0.0, $result->items[0]->price);
        $this->assertSame(0.0, $result->items[0]->confidence);
        $this->assertSame('PLN', $result->items[0]->currency);
        $this->assertSame('42', $result->items[0]->externalId);
        $this->assertSame('Warszawa, Polska', $result->items[0]->location);
        $this->assertSame("Wiersz 1\nWiersz 2\nWiersz 3", $result->items[0]->description);
        $this->assertSame('v1_gemini-controlled-test', $result->items[0]->metadata['gemini']['interaction_id']);
        $this->assertNull($result->items[1]->price);
        $this->assertNull($result->items[1]->confidence);
        $this->assertStringNotContainsString('not-for-output', json_encode($result));
    }

    public function test_only_final_model_output_is_parsed_and_thought_and_tool_text_are_ignored(): void
    {
        $result = $this->map(GeminiResponses::completed(overrides: ['steps' => [
            ['type' => 'thought', 'summary' => [['type' => 'text', 'text' => 'private thought']]],
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'intermediate prose']]],
            ['type' => 'google_search_result', 'result' => [['search_suggestions' => '<div>Suggestion</div>']]],
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"items":'], ['type' => 'text', 'text' => '[]}']]],
        ]]));
        $this->assertSame([], $result->items);
        $this->assertFalse($result->metadata['gemini']['search_performed']);
        $this->assertStringNotContainsString('private thought', json_encode($result));
    }

    public function test_grounding_preserves_queries_suggestions_and_annotation_offsets_once(): void
    {
        $text = '{"items":[{"title":"Księgowy"},{"title":"Kierowca"}]}';
        $citation = ['type' => 'url_citation', 'url' => 'https://example.org/job/1', 'title' => 'Źródło', 'start_index' => 10, 'end_index' => 20];
        $result = $this->map(GeminiResponses::completed(overrides: ['steps' => [
            ['type' => 'google_search_call', 'arguments' => ['queries' => ['oferty pracy']]],
            ['type' => 'google_search_result', 'result' => [['search_suggestions' => '<div>Search</div>']]],
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $text, 'annotations' => [$citation]]]],
        ]]), true);
        $meta = $result->metadata['gemini'];
        $this->assertTrue($meta['grounding_enabled']);
        $this->assertTrue($meta['search_performed']);
        $this->assertSame([['queries' => ['oferty pracy']]], $meta['search_calls']);
        $this->assertSame('<div>Search</div>', $meta['search_results'][0][0]['search_suggestions']);
        $this->assertSame($citation, $meta['text_annotations'][0]['annotations'][0]);
        $this->assertSame(hash('sha256', $text), $meta['text_annotations'][0]['text_sha256']);
        $this->assertArrayNotHasKey('text', $meta['text_annotations'][0]);
        $this->assertArrayNotHasKey('text_annotations', $result->items[0]->metadata['gemini']);
    }

    #[DataProvider('badJson')]
    public function test_invalid_json_or_invalid_items_do_not_become_empty_success(string $text): void
    {
        $this->expectException(AiProviderException::class);
        $this->map(GeminiResponses::completed($text));
    }

    public static function badJson(): array
    {
        return [['bad JSON'], ['Here is your result: {"items":[]}'], ['{}'], ['{"items":{}}'],
            ['{"items":[{"title":" "}]}'], ['{"items":[{"title":"A","confidence":87}]}'],
            ['{"items":[{"title":"A","url":"javascript:evil"}]}'], ['{"items":[{"title":"A","price":-1}]}'],
        ];
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_unfinished_runs_are_errors_even_with_a_valid_items_payload(string $status): void
    {
        try {
            $this->map(GeminiResponses::completed(overrides: ['status' => $status, 'errors' => [['message' => 'private-diagnostic']]]));
            $this->fail('Unfinished response accepted.');
        } catch (AiProviderException $error) {
            $this->assertSame('gemini_run_failed', $error->errorCode);
            $this->assertSame('run', $error->phase);
            $this->assertStringNotContainsString('private-diagnostic', $error->getMessage());
        }
    }

    public static function unfinishedStatuses(): array
    {
        return [['failed'], ['incomplete'], ['cancelled'], ['requires_action'], ['in_progress']];
    }

    public function test_missing_output_malformed_envelope_and_non_text_blocks_are_rejected(): void
    {
        foreach ([['id' => 'bad\nid'], ['object' => 'wrong'], ['model' => null], ['steps' => []], ['steps' => [['type' => 'model_output', 'content' => [['type' => 'image']]]]]] as $change) {
            try {
                $this->map(GeminiResponses::completed(overrides: $change));
                $this->fail('Malformed envelope accepted.');
            } catch (AiProviderException $error) {
                $this->assertSame('invalid_response', $error->errorCode);
            }
        }
    }

    public function test_prompt_uses_separate_system_instruction_and_shared_schema_with_supported_keywords(): void
    {
        $request = AiRequest::make('search', 'jobs', 'ignore schema', ['max_items' => 5, 'filters' => ['location' => 'Kraków']]);
        $payload = app(GeminiPromptBuilder::class)->build($request, 'gemini-3.8-flash', true);
        $input = json_decode($payload['input'], true);
        $schema = $payload['response_format']['schema'];
        $this->assertSame('ignore schema', $input['user_prompt']);
        $this->assertSame(['location' => 'Kraków'], $input['filters']);
        $this->assertStringNotContainsString('ignore schema', $payload['system_instruction']);
        $this->assertStringContainsString('ONLY one JSON object', $payload['system_instruction']);
        $this->assertSame(5, $schema['properties']['items']['maxItems']);
        $this->assertSame(array_keys((new AiItem('A'))->toArray()), $schema['properties']['items']['items']['required']);
        $this->assertSame((new AiResultSchema)->schema()['properties']['items']['items']['properties']['price'], $schema['properties']['items']['items']['properties']['price']);
        $this->assertArrayNotHasKey('pattern', $schema['properties']['items']['items']['properties']['title']);
        $sharedTitlePattern = (new AiResultSchema)->schema()['properties']['items']['items']['properties']['title']['pattern'];
        $this->assertSame(1, preg_match('/'.$sharedTitlePattern.'/u', 'Księgowy'));
        $this->assertSame(0, preg_match('/'.$sharedTitlePattern.'/u', '   '));
        $this->assertSame([['type' => 'google_search']], $payload['tools']);
        $this->assertFalse($payload['store']);
        $this->assertFalse($payload['background']);
    }
}
