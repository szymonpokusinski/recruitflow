<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AiPayloadGuard;
use App\Services\Ai\AiResultMapper;
use App\Services\Ai\DTO\AiItem;
use App\Services\Ai\DTO\AiRequest;
use App\Services\Ai\DTO\AiResult;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiResultMapperTest extends TestCase
{
    private function payload(array $item = []): array
    {
        return [
            'provider' => 'test_driver', 'model' => 'test-model', 'category' => 'job_offers',
            'requested_at' => '2026-10-14T10:00:00+00:00',
            'items' => [$item + ['title' => 'Księgowy']],
        ];
    }

    public function test_mapper_preserves_zero_missing_fields_and_multiline_text(): void
    {
        $payload = $this->payload([
            'price' => '0', 'confidence' => 0, 'currency' => 'PLN',
            'url' => 'https://example.org/jobs/123', 'external_id' => '123',
            'description' => "Pierwszy wiersz\nDrugi wiersz", 'raw' => ['source_field' => 'oryginał'],
        ]);
        $result = app(AiResultMapper::class)->map($payload);
        $this->assertInstanceOf(AiResult::class, $result);
        $this->assertInstanceOf(AiItem::class, $result->items[0]);
        $this->assertSame(0.0, $result->items[0]->price);
        $this->assertSame(0.0, $result->items[0]->confidence);
        $this->assertNull($result->items[0]->location);
        $this->assertSame($payload['items'][0]['description'], $result->items[0]->description);
        $this->assertSame('123', $result->items[0]->externalId);
        $this->assertSame(['source_field' => 'oryginał'], $result->items[0]->raw);
        $this->assertSame($result->toArray(), json_decode(json_encode($result, JSON_PRESERVE_ZERO_FRACTION), true));
        $this->assertSame($result->toArray(), app(AiResultMapper::class)->map($result->toArray())->toArray());
    }

    public function test_nulls_and_empty_results_are_legitimate_not_fabricated_scores(): void
    {
        $payload = $this->payload(['price' => null, 'confidence' => null]);
        $result = app(AiResultMapper::class)->map($payload);
        $this->assertNull($result->items[0]->price);
        $this->assertNull($result->items[0]->confidence);
        $payload['items'] = [];
        $this->assertSame([], app(AiResultMapper::class)->map($payload)->items);
    }

    public function test_blank_optional_numbers_do_not_become_zero(): void
    {
        $result = app(AiResultMapper::class)->map($this->payload([
            'price' => '', 'confidence' => ' ', 'url' => '', 'currency' => '', 'external_id' => '',
        ]));
        $this->assertNull($result->items[0]->price);
        $this->assertNull($result->items[0]->confidence);
        $this->assertNull($result->items[0]->url);
        $this->assertNull($result->items[0]->currency);
        $this->assertNull($result->items[0]->externalId);
    }

    #[DataProvider('invalidItems')]
    public function test_invalid_provider_values_are_rejected_before_casting(array $item): void
    {
        $this->expectException(ValidationException::class);
        app(AiResultMapper::class)->map($this->payload($item));
    }

    public static function invalidItems(): array
    {
        return [
            'negative price' => [['price' => -1]],
            'invalid number' => [['price' => 'nie wiadomo']],
            'low confidence' => [['confidence' => -0.1]],
            'high confidence' => [['confidence' => 100]],
            'script URL' => [['url' => 'javascript:alert(1)']],
            'file URL' => [['url' => 'file:///etc/passwd']],
            'currency' => [['currency' => 'zł']],
            'empty title' => [['title' => '   ']],
            'unknown field' => [['favorite' => true]],
            'invalid raw' => [['raw' => 'not an object']],
            'nonfinite number' => [['price' => INF]],
            'non JSON metadata' => [['metadata' => ['date' => new \DateTimeImmutable]]],
        ];
    }

    public function test_invalid_envelope_and_non_list_items_are_rejected(): void
    {
        foreach ([['provider' => '../driver'], ['model' => ' '], ['requested_at' => 'tomorrow'], ['items' => 'not a list'], ['items' => ['record' => ['title' => 'A']]], ['unknown' => true]] as $change) {
            try {
                app(AiResultMapper::class)->map(array_replace($this->payload(), $change));
                $this->fail('Invalid envelope accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_nested_credentials_are_redacted_without_changing_description(): void
    {
        $result = app(AiResultMapper::class)->map($this->payload([
            'description' => 'Opis stanowiska zachowany bez zmian.',
            'raw' => ['http' => ['Authorization' => 'Bearer never-store', 'access_token' => 'never-store']],
            'metadata' => ['apiKey' => 'never-store', 'CURSOR_API_KEY' => 'never-store', 'company' => 'Firma'],
        ]));
        $this->assertStringNotContainsString('never-store', json_encode($result));
        $this->assertSame('[REDACTED]', $result->items[0]->raw['http']['Authorization']);
        $this->assertSame('Firma', $result->items[0]->metadata['company']);
        $this->assertSame('Opis stanowiska zachowany bez zmian.', $result->items[0]->description);
    }

    public function test_request_validates_options_and_never_accepts_credentials(): void
    {
        $request = AiRequest::make('analyze', 'job_offers', 'Sprawdź wymagania.', ['temperature' => 0, 'max_items' => 5]);
        $this->assertSame('analyze', $request->operation);
        foreach ([['api_key' => 'secret'], ['CURSOR_API_KEY' => 'secret'], ['headers' => ['Authorization' => 'secret']], ['max_items' => 0], ['model' => ' '], ['temperature' => 3]] as $options) {
            try {
                AiRequest::make('search', 'job_offers', 'Prompt.', $options);
                $this->fail('Invalid options accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_payload_size_and_depth_are_bounded(): void
    {
        $guard = new AiPayloadGuard;
        foreach ([['large' => str_repeat('x', 2_000_001)], array_reduce(range(1, 40), fn ($nested) => ['nested' => $nested], [])] as $data) {
            try {
                $guard->validateJson($data, 'result');
                $this->fail('Unbounded JSON accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_result_enforces_typed_item_collection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AiResult('fake', null, 'job_offers', CarbonImmutable::now(), [['title' => 'untyped']]);
    }
}
