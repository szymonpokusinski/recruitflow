<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Contracts\CredentialStore;
use App\Models\MailAccount;
use App\Services\MailAccountService;
use App\Services\MailProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailProviderResolverTest extends TestCase
{
    use RefreshDatabase;

    private MailAccountMemoryStore $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = new MailAccountMemoryStore;
        $this->app->instance(CredentialStore::class, $this->vault);
    }

    public static function providerMappingCases(): array
    {
        return [
            // Gmail
            ['user@gmail.com', 'gmail', 'Gmail', true],
            ['user@googlemail.com', 'gmail', 'Gmail', true],
            ['User.Name+tag@GMAIL.COM', 'gmail', 'Gmail', true],

            // WP
            ['jan.kowalski@wp.pl', 'wp', 'Poczta WP', true],
            ['jan.kowalski@wp.eu', 'wp', 'Poczta WP', true],

            // o2
            ['kontakt@o2.pl', 'o2', 'Poczta o2', true],
            ['kontakt@tlen.pl', 'o2', 'Poczta o2', true],
            ['kontakt@prokonto.pl', 'o2', 'Poczta o2', true],

            // Onet
            ['rekruter@onet.pl', 'onet', 'Onet Poczta', true],
            ['rekruter@onet.eu', 'onet', 'Onet Poczta', true],
            ['rekruter@poczta.onet.pl', 'onet', 'Onet Poczta', true],
            ['rekruter@op.pl', 'onet', 'Onet Poczta', true],
            ['rekruter@vp.pl', 'onet', 'Onet Poczta', true],
            ['rekruter@autograf.pl', 'onet', 'Onet Poczta', true],
            ['rekruter@amorki.pl', 'onet', 'Onet Poczta', true],
            ['rekruter@spoko.pl', 'onet', 'Onet Poczta', true],
            ['rekruter@onet.com.pl', 'onet', 'Onet Poczta', true],

            // Interia
            ['adam@interia.pl', 'interia', 'Poczta Interia', true],
            ['adam@interia.eu', 'interia', 'Poczta Interia', true],
            ['adam@poczta.fm', 'interia', 'Poczta Interia', true],
            ['adam@interbit.pl', 'interia', 'Poczta Interia', true],
            ['adam@interia.com', 'interia', 'Poczta Interia', true],
            ['adam@vip.interia.pl', 'interia', 'Poczta Interia', true],
            ['adam@intmail.pl', 'interia', 'Poczta Interia', true],
            ['adam@interiowy.pl', 'interia', 'Poczta Interia', true],
            ['adam@1gb.pl', 'interia', 'Poczta Interia', true],
            ['adam@2gb.pl', 'interia', 'Poczta Interia', true],

            // Gazeta.pl
            ['biuro@gazeta.pl', 'gazeta', 'Gazeta.pl', true],
            ['biuro@wyborcza.pl', 'gazeta', 'Gazeta.pl', true],
            ['kontakt@g.pl', 'gazeta', 'Gazeta.pl', true],

            // Outlook / Hotmail / Live
            ['candidate@outlook.com', 'outlook', 'Outlook', true],
            ['candidate@hotmail.com', 'outlook', 'Outlook', true],
            ['candidate@live.com', 'outlook', 'Outlook', true],
            ['candidate@msn.com', 'outlook', 'Outlook', true],
            ['candidate@outlook.pl', 'outlook', 'Outlook', true],
            ['candidate@hotmail.pl', 'outlook', 'Outlook', true],
            ['candidate@live.pl', 'outlook', 'Outlook', true],

            // Generic / unknown / company domains
            ['hr@firma.pl', 'generic', 'Własny / Nieznany dostawca', false],
            ['ceo@google-workspace-corp.pl', 'generic', 'Własny / Nieznany dostawca', false],
            ['user@microsoft365-company.com', 'generic', 'Własny / Nieznany dostawca', false],
            ['nobody@somedomain.org', 'generic', 'Własny / Nieznany dostawca', false],
        ];
    }

    #[DataProvider('providerMappingCases')]
    public function test_resolves_all_required_providers_and_aliases(
        string $email,
        string $expectedId,
        string $expectedName,
        bool $expectedIsKnown
    ): void {
        $resolver = app(MailProviderResolver::class);
        $result = $resolver->resolveFromEmail($email);

        $this->assertSame($expectedId, $result['provider']);
        $this->assertSame($expectedName, $result['name']);
        $this->assertSame($expectedIsKnown, $result['is_known']);
    }

    public function test_rejects_accidental_substring_matches(): void
    {
        $resolver = app(MailProviderResolver::class);

        $fakes = [
            'test@notwp.pl',
            'test@wp.pl.fake.com',
            'test@my-wp.pl',
            'test@fakegmail.com',
            'test@gmail.com.co',
            'test@interia.pl.subdomain.com',
            'wp.pl@attacker.com',
            'gmail.com@evil.org',
        ];

        foreach ($fakes as $fakeEmail) {
            $result = $resolver->resolveFromEmail($fakeEmail);
            $this->assertSame('generic', $result['provider'], "Failed on {$fakeEmail}");
            $this->assertFalse($result['is_known'], "Should not be known: {$fakeEmail}");
        }
    }

    public function test_handles_malformed_and_edge_case_inputs(): void
    {
        $resolver = app(MailProviderResolver::class);

        $invalids = [
            '',
            '    ',
            'not-an-email',
            '@gmail.com',
            'user@',
            'user@domain',
            'user@domain .com',
            'user@.com',
        ];

        foreach ($invalids as $invalid) {
            $result = $resolver->resolveFromEmail($invalid);
            $this->assertSame('generic', $result['provider']);
            $this->assertFalse($result['is_known']);
        }

        // Clean domain extraction with surrounding spaces and trailing dot
        $result = $resolver->resolveFromEmail('  test@WP.PL.  ');
        $this->assertSame('wp', $result['provider']);
        $this->assertTrue($result['is_known']);
    }

    public function test_resolve_provider_api_endpoint(): void
    {
        // POST request
        $response = $this->postJson('/api/mail-accounts/resolve-provider', [
            'email' => 'test@wp.pl',
        ]);

        $response->assertOk()
            ->assertJsonPath('provider', 'wp')
            ->assertJsonPath('name', 'Poczta WP')
            ->assertJsonPath('is_known', true)
            ->assertJsonPath('domain', 'wp.pl');

        // GET request
        $response = $this->getJson('/api/mail-accounts/resolve-provider?email=test@gmail.com');

        $response->assertOk()
            ->assertJsonPath('provider', 'gmail')
            ->assertJsonPath('name', 'Gmail')
            ->assertJsonPath('is_known', true);

        // Unknown company domain
        $response = $this->postJson('/api/mail-accounts/resolve-provider', [
            'email' => 'contact@firma.pl',
        ]);

        $response->assertOk()
            ->assertJsonPath('provider', 'generic')
            ->assertJsonPath('name', 'Własny / Nieznany dostawca')
            ->assertJsonPath('is_known', false)
            ->assertJsonPath('domain', 'firma.pl');
    }

    public function test_automatic_provider_assignment_on_create_and_update(): void
    {
        $service = app(MailAccountService::class);

        // 1. Create account with WP email
        $account = $service->create([
            'email' => 'recruiter@wp.pl',
            'password' => 'Pass123',
        ]);

        $this->assertSame('wp', $account->provider);
        $this->assertDatabaseHas('mail_accounts', [
            'id' => $account->id,
            'provider' => 'wp',
        ]);

        // 2. Update to Gmail
        $updated = $service->update($account, [
            'email' => 'recruiter@gmail.com',
        ]);

        $this->assertSame('gmail', $updated->provider);
        $this->assertDatabaseHas('mail_accounts', [
            'id' => $account->id,
            'provider' => 'gmail',
        ]);

        // 3. Update to unknown company domain
        $updated = $service->update($account, [
            'email' => 'recruiter@startup.io',
        ]);

        $this->assertSame('generic', $updated->provider);
        $this->assertDatabaseHas('mail_accounts', [
            'id' => $account->id,
            'provider' => 'generic',
        ]);

        // 4. Password remains intact through provider updates
        $this->assertSame('Pass123', $service->getPassword($account->fresh()));
    }
}
