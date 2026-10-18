<?php

declare(strict_types=1);

namespace App\Services;

use App\Integrations\Contracts\CredentialStore;
use App\Models\MailAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class MailAccountService
{
    public function __construct(
        private readonly CredentialStore $credentialStore,
        private readonly MailProviderResolver $providerResolver,
    ) {}

    /**
     * @return Collection<int, MailAccount>
     */
    public function list(): Collection
    {
        return MailAccount::query()
            ->orderByDesc('created_at')
            ->get();
    }

    public function get(string $id): MailAccount
    {
        return MailAccount::findOrFail($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): MailAccount
    {
        $password = $data['password'] ?? null;
        unset($data['password']);

        if (is_string($password) && $password !== '') {
            $credentialRef = (string) Str::uuid();
            $this->credentialStore->write($credentialRef, ['password' => $password]);
            $data['credential_ref'] = $credentialRef;
        }

        if (! empty($data['email'])) {
            $resolved = $this->providerResolver->resolveFromEmail((string) $data['email']);
            $data['provider'] = $resolved['provider'];
            if (! isset($data['auth_type'])) {
                $data['auth_type'] = $resolved['auth_type'] ?? 'password';
            }
            if (! isset($data['oauth_provider'])) {
                $data['oauth_provider'] = $resolved['oauth_provider'] ?? null;
            }
        }

        return MailAccount::create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(MailAccount $account, array $data): MailAccount
    {
        $password = $data['password'] ?? null;
        unset($data['password']);

        // Empty password means no change
        if (is_string($password) && $password !== '') {
            $credentialRef = $account->credential_ref ?: (string) Str::uuid();
            $this->credentialStore->write($credentialRef, ['password' => $password]);
            $data['credential_ref'] = $credentialRef;
        }

        // When email changes (or is updated), automatically resolve and update provider
        if (isset($data['email']) && is_string($data['email'])) {
            $resolved = $this->providerResolver->resolveFromEmail($data['email']);
            $data['provider'] = $resolved['provider'];
        }

        $account->update($data);

        return $account->fresh();
    }

    public function toggleAutoSync(MailAccount $account, ?bool $enabled = null): MailAccount
    {
        $newVal = $enabled ?? ! (bool) $account->auto_sync;
        $account->update(['auto_sync' => $newVal]);

        return $account->fresh();
    }

    public function delete(MailAccount $account): void
    {
        if ($account->credential_ref) {
            $canDeleteCredential = true;
            if ($account->auth_type === 'oauth') {
                $integrationAccount = \App\Models\Integrations\IntegrationAccount::where('credential_ref', $account->credential_ref)->first();
                if ($integrationAccount && $integrationAccount->sources()->count() > 0) {
                    $canDeleteCredential = false;
                }
            }

            if ($canDeleteCredential) {
                $this->credentialStore->delete($account->credential_ref);
            }
        }

        $account->delete();
    }

    public function getPassword(MailAccount $account): ?string
    {
        if (! $account->credential_ref) {
            return null;
        }

        $credentials = $this->credentialStore->read($account->credential_ref);

        return $credentials['password'] ?? null;
    }
}
