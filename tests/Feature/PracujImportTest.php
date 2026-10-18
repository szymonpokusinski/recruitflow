<?php

namespace Tests\Feature;

use App\Integrations\PracujPl\HttpResult;
use App\Integrations\PracujPl\HttpTransport;
use App\Integrations\PracujPl\ImportException;
use App\Integrations\PracujPl\PracujPlParser;
use App\Integrations\PracujPl\PracujUrl;
use App\Integrations\PracujPl\PublicDnsResolver;
use App\Jobs\SynchronizeRecruitment;
use App\Models\Recruitment\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class PracujImportTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(8, 0));
        $this->fixture = require base_path('tests/Fixtures/PracujPl/observed-1005120488.php');
        Bus::fake([SynchronizeRecruitment::class]);
        $this->app->instance(PublicDnsResolver::class, new class extends PublicDnsResolver
        {
            public function address(string $host): string
            {
                if (! in_array($host, ['www.pracuj.pl', 'pracuj.pl'], true)) {
                    throw new ImportException('unsafe_address', 'Nieznany host.');
                }

                return '1.1.1.1';
            }
        });
    }

    private function url(?string $slug = null): string
    {
        return 'https://www.pracuj.pl/praca/'.($slug ?? $this->fixture['slug']).',oferta,'.$this->fixture['id'];
    }

    private function html(?array $offer = null, ?array $jsonld = null, bool $next = true, bool $ld = true): string
    {
        $offer ??= $this->fixture['offer'];
        $jsonld ??= $this->fixture['jsonld'];
        $state = [
            'props' => [
                'pageProps' => [
                    'dehydratedState' => [
                        'queries' => [
                            ['queryKey' => ['jobOffer', $this->fixture['id'], 'pl'], 'state' => ['data' => $offer]],
                        ],
                    ],
                ],
            ],
        ];

        return '<html><head>'.($next ? '<script id="__NEXT_DATA__">'.json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).'</script>' : '').($ld ? '<script id="job-schema-org" type="application/ld+json">'.json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).'</script>' : '').'</head><body></body></html>';
    }

    private function parser(): PracujPlParser
    {
        $transport = new class implements HttpTransport
        {
            public HttpResult $result;

            public int $calls = 0;

            public function get(string $url, string $pinnedAddress): HttpResult
            {
                $this->calls++;

                return $this->result;
            }
        };
        $transport->result = new HttpResult(200, ['content-type' => 'text/html'], $this->html());
        $this->app->instance(HttpTransport::class, $transport);

        return $this->app->make(PracujPlParser::class);
    }

    public function test_observed_offer_populates_universal_fields_without_database_writes(): void
    {
        $draft = $this->parser()->preview($this->url().'?utm_source=test');
        $this->assertSame('1005120488', $draft->providerOfferId);
        $this->assertSame($this->url(), $draft->canonicalUrl);
        $this->assertSame('KALA sp. z o.o.', $draft->form['company']);
        $this->assertSame('Specjalista do spraw obsługi klienta', $draft->form['title']);
        $this->assertSame('Legnica', $draft->form['location']);
        $this->assertSame('Stacjonarnie', $draft->form['workMode']);
        $this->assertEquals(5600, $draft->form['salaryMin']);
        $this->assertEquals(8000, $draft->form['salaryMax']);
        $this->assertSame('Brutto', $draft->form['salaryBasis']);
        $this->assertSame('Miesiąc', $draft->form['salaryPeriod']);
        $this->assertSame(['Doświadczenie na podobnym stanowisku'], $draft->form['requirements']);
        $this->assertSame('2026-09-28', $draft->form['publishedAt']);
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('sync_outbox', 0);
    }

    public function test_hourly_remote_technology_offer_uses_observed_codes(): void
    {
        $offer = $this->fixture['offer'];
        $offer['attributes']['employment']['workModes'] = [['code' => 'home-office', 'name' => 'praca zdalna']];
        $offer['attributes']['employment']['typesOfContracts'] = [['name' => 'kontrakt B2B', 'salary' => ['currency' => ['code' => 'PLN'], 'from' => 160, 'to' => 160, 'salaryKind' => ['code' => 'net-plus-vat'], 'timeUnit' => ['shortForm' => ['name' => 'godz.']]]]];
        $offer['sections'][] = ['sectionType' => 'technologies', 'subSections' => [['sectionType' => 'technologies-expected', 'model' => ['modelType' => 'open-dictionary', 'items' => [], 'customItems' => [['name' => 'Java'], ['name' => 'Go']]]], ['sectionType' => 'technologies-optional', 'model' => ['modelType' => 'open-dictionary', 'items' => [], 'customItems' => [['name' => 'React.js']]]]]];
        $draft = $this->parser()->parse(PracujUrl::from($this->url()), $this->html($offer));
        $this->assertSame('Zdalnie', $draft->form['workMode']);
        $this->assertSame('Netto + VAT', $draft->form['salaryBasis']);
        $this->assertSame('Godzina', $draft->form['salaryPeriod']);
        $this->assertSame(['Java', 'Go'], $draft->form['requiredSkills']);
        $this->assertSame(['React.js'], $draft->form['preferredSkills']);
    }

    public function test_hybrid_mode_and_zero_salary_are_preserved_as_real_values(): void
    {
        $offer = $this->fixture['offer'];
        $offer['attributes']['employment']['workModes'] = [['code' => 'hybrid', 'name' => 'praca hybrydowa']];
        $offer['attributes']['employment']['typesOfContracts'][0]['salary']['from'] = 0;
        $offer['attributes']['employment']['typesOfContracts'][0]['salary']['to'] = 0;
        $draft = $this->parser()->parse(PracujUrl::from($this->url()), $this->html($offer));
        $this->assertSame('Hybrydowo', $draft->form['workMode']);
        $this->assertSame(0.0, $draft->form['salaryMin']);
        $this->assertSame(0.0, $draft->form['salaryMax']);
    }

    public function test_multiple_contracts_and_locations_remain_structured_without_selecting_a_salary(): void
    {
        $offer = $this->fixture['offer'];
        $offer['attributes']['workplaces'][] = ['country' => ['name' => 'Polska'], 'region' => ['name' => 'mazowieckie'], 'displayAddress' => 'Warszawa', 'inlandLocation' => ['location' => ['name' => 'Warszawa']]];
        $offer['attributes']['employment']['typesOfContracts'][] = ['name' => 'kontrakt B2B', 'salary' => ['currency' => ['code' => 'PLN'], 'from' => 100, 'to' => 140, 'salaryKind' => ['code' => 'net-plus-vat'], 'timeUnit' => ['shortForm' => ['name' => 'godz.']]]];
        $draft = $this->parser()->parse(PracujUrl::from($this->url()), $this->html($offer));
        $this->assertSame('partial', $draft->status);
        $this->assertCount(2, $draft->details['locations']);
        $this->assertCount(2, $draft->details['salaryVariants']);
        $this->assertSame(['umowa o pracę', 'kontrakt B2B'], $draft->details['employmentTypes']);
        $this->assertArrayNotHasKey('salaryMin', $draft->form);
        $this->assertArrayNotHasKey('salaryPeriod', $draft->form);
    }

    public function test_missing_salary_stays_empty_and_json_ld_graph_is_supported(): void
    {
        $offer = $this->fixture['offer'];
        $offer['attributes']['employment']['typesOfContracts'][0]['salary'] = null;
        $draft = $this->parser()->parse(PracujUrl::from($this->url()), $this->html($offer, ['@graph' => [['@type' => 'Organization', 'name' => 'x'], ['@type' => 'JobPosting', 'title' => 'Specjalista do spraw obsługi klienta', 'hiringOrganization' => 'KALA sp. z o.o.']]]));
        $this->assertArrayNotHasKey('salaryMin', $draft->form);
        $this->assertContains('Nie udało się odczytać wynagrodzenia. Możesz uzupełnić je ręcznie.', $draft->warnings);
        $ld = $this->parser()->parse(PracujUrl::from($this->url()), $this->html(null, ['@graph' => [['@type' => 'JobPosting', 'title' => 'Samodzielny księgowy', 'hiringOrganization' => ['name' => 'Firma A'], 'jobLocation' => ['address' => ['addressLocality' => 'Kraków']]]]], false));
        $this->assertSame('Kraków', $ld->form['location']);
        $this->assertSame('Firma A', $ld->form['company']);
    }

    public function test_broken_next_data_falls_back_to_json_ld_and_missing_json_ld_keeps_embedded_data(): void
    {
        $broken = '<html><script id="__NEXT_DATA__">{broken</script><script type="application/ld+json">'.json_encode($this->fixture['jsonld']).'</script></html>';
        $fallback = $this->parser()->parse(PracujUrl::from($this->url()), $broken);
        $this->assertSame('partial', $fallback->status);
        $this->assertSame('KALA sp. z o.o.', $fallback->form['company']);
        $embedded = $this->parser()->parse(PracujUrl::from($this->url()), $this->html(null, null, true, false));
        $this->assertSame('Specjalista do spraw obsługi klienta', $embedded->form['title']);
    }

    public function test_invalid_html_and_expired_offer_return_diagnostic_errors(): void
    {
        try {
            $this->parser()->parse(PracujUrl::from($this->url()), '<html>no data</html>');
            $this->fail('Expected parser error');
        } catch (ImportException $exception) {
            $this->assertSame('parse_error', $exception->reason);
        }
        $offer = $this->fixture['offer'];
        $offer['publicationDetails']['isActive'] = false;
        try {
            $this->parser()->parse(PracujUrl::from($this->url()), $this->html($offer));
            $this->fail('Expected expired error');
        } catch (ImportException $exception) {
            $this->assertSame('expired', $exception->reason);
        }
    }

    public function test_unexpected_embedded_schema_returns_parse_error_instead_of_server_exception(): void
    {
        $offer = $this->fixture['offer'];
        $offer['sections'] = 'unexpected format';
        try {
            $this->parser()->parse(PracujUrl::from($this->url()), $this->html($offer));
            $this->fail('Expected parser error');
        } catch (ImportException $exception) {
            $this->assertSame('parse_error', $exception->reason);
            $this->assertSame(502, $exception->httpStatus);
        }
    }

    public function test_preview_api_is_read_only_and_explicit_save_uses_existing_sqlite_flow(): void
    {
        $this->parser();
        $preview = $this->postJson('/api/offers/import/preview', ['url' => $this->url().'?utm_source=qa'])->assertOk()->assertJsonPath('status', 'success')->json('draft');
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('sync_outbox', 0);
        $id = $this->postJson('/api/recruitment/offers', ['operationId' => (string) Str::uuid()] + $preview['form'] + ['importDetails' => $preview['details']])->assertOk()->json('id');
        $offer = Offer::findOrFail($id);
        $this->assertSame('LOCAL_PRACUJ_1005120488', $offer->external_key);
        $this->assertSame('Legnica', $offer->data['location']);
        $this->assertSame('pracuj.pl', $offer->data['importDetails']['provider']);
        $this->assertDatabaseCount('sync_outbox', 2);
        $this->postJson('/api/offers/import/preview', ['url' => $this->url('inna-nazwa-oferty')])->assertOk()->assertJsonPath('status', 'duplicate')->assertJsonPath('duplicate.id', $id);
        $this->postJson('/api/recruitment/offers', ['operationId' => (string) Str::uuid(), 'offerUrl' => $this->url('inna-nazwa-oferty')])->assertUnprocessable()->assertJsonValidationErrors('offerUrl');
        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseCount('integration_settings', 0);
    }

    public function test_known_duplicate_does_not_request_pracuj_again(): void
    {
        $this->parser();
        $draft = $this->postJson('/api/offers/import/preview', ['url' => $this->url()])->assertOk()->json('draft');
        $id = $this->postJson('/api/recruitment/offers', ['operationId' => (string) Str::uuid()] + $draft['form'] + ['importDetails' => $draft['details']])->assertOk()->json('id');
        $this->app->instance(HttpTransport::class, new class implements HttpTransport
        {
            public function get(string $url, string $pinnedAddress): HttpResult
            {
                throw new \RuntimeException('Duplicate lookup should not fetch a remote page.');
            }
        });
        $this->postJson('/api/offers/import/preview', ['url' => $this->url('zmieniona-nazwa').'?utm_campaign=test'])
            ->assertOk()->assertJsonPath('status', 'duplicate')->assertJsonPath('duplicate.id', $id);
    }

    public function test_unsupported_urls_and_remote_statuses_have_distinct_api_states(): void
    {
        $transport = new class implements HttpTransport
        {
            public HttpResult $result;

            public function get(string $url, string $pinnedAddress): HttpResult
            {
                return $this->result;
            }
        };
        $this->app->instance(HttpTransport::class, $transport);
        foreach (['http://www.pracuj.pl/praca/x,oferta,1005120488', 'https://pracuj.pl.evil.example/praca/x,oferta,1005120488', 'https://127.0.0.1/praca/x,oferta,1005120488', 'https://www.pracuj.pl/praca'] as $unsafe) {
            $this->postJson('/api/offers/import/preview', ['url' => $unsafe])->assertStatus(422)->assertJsonPath('status', 'unsupported_url');
        }
        foreach ([403 => 'access_denied', 404 => 'expired', 410 => 'expired', 429 => 'rate_limited', 503 => 'remote_error'] as $status => $reason) {
            $transport->result = new HttpResult($status, ['content-type' => 'text/html'], '');
            $this->postJson('/api/offers/import/preview', ['url' => $this->url()])->assertJsonPath('status', $reason);
        }
        $transport->result = new HttpResult(302, ['location' => 'https://127.0.0.1/private'], '');
        $this->postJson('/api/offers/import/preview', ['url' => $this->url()])->assertJsonPath('status', 'unsafe_redirect');
    }

    public function test_timeout_is_reported_without_writing_local_records(): void
    {
        $this->app->instance(HttpTransport::class, new class implements HttpTransport
        {
            public function get(string $url, string $pinnedAddress): HttpResult
            {
                throw new ImportException('timeout', 'Pobieranie ogłoszenia przekroczyło limit czasu.', 502);
            }
        });
        $this->postJson('/api/offers/import/preview', ['url' => $this->url()])->assertStatus(502)->assertJsonPath('status', 'timeout');
        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('sync_outbox', 0);
    }

    public function test_dns_rejects_private_or_mixed_addresses_before_http(): void
    {
        foreach ([['127.0.0.1'], ['1.1.1.1', '10.0.0.1'], ['169.254.169.254']] as $addresses) {
            $resolver = new class($addresses) extends PublicDnsResolver
            {
                public function __construct(private array $addresses) {}

                protected function lookup(string $host): array
                {
                    return $this->addresses;
                }
            };
            try {
                $resolver->address('www.pracuj.pl');
                $this->fail('Expected private IP rejection');
            } catch (ImportException $exception) {
                $this->assertSame('unsafe_address', $exception->reason);
            }
        }
    }

    public function test_divergent_json_ld_does_not_override_embedded_fields(): void
    {
        $ld = $this->fixture['jsonld'];
        $ld['title'] = 'Inny tytuł';
        $draft = $this->parser()->parse(PracujUrl::from($this->url()), $this->html(null, $ld));
        $this->assertSame('Specjalista do spraw obsługi klienta', $draft->form['title']);
        $this->assertNotEmpty($draft->warnings);
        $this->assertSame('embedded_json', $draft->fieldSources['title']);
    }
}
