<?php

declare(strict_types=1);

namespace App\Services;

class MailProviderResolver
{
    /**
     * Map of normalized domain to provider ID.
     *
     * @var array<string, string>|null
     */
    private ?array $domainMap = null;

    /**
     * Normalize and extract domain from an email address.
     * Accidental substrings (e.g. in the username or partial domain) are rejected.
     */
    public function extractDomain(string $email): ?string
    {
        $email = trim($email);

        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        // Split by the last '@' to protect against unusual local-part formats
        $lastAtPos = strrpos($email, '@');
        if ($lastAtPos === false || $lastAtPos === 0 || $lastAtPos === strlen($email) - 1) {
            return null;
        }

        $domain = substr($email, $lastAtPos + 1);
        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '.');

        // Basic domain validation: must contain a period, no whitespace, valid domain chars
        if ($domain === '' || ! str_contains($domain, '.') || preg_match('/\s/', $domain)) {
            return null;
        }

        return $domain;
    }

    /**
     * Resolve provider information from an email address.
     *
     * @return array{
     *     provider: string,
     *     name: string,
     *     is_known: bool,
     *     domain: string|null
     * }
     */
    public function resolveFromEmail(string $email): array
    {
        $domain = $this->extractDomain($email);

        if ($domain === null) {
            return $this->buildGenericResponse(null);
        }

        $domainMap = $this->getDomainMap();

        if (isset($domainMap[$domain])) {
            $providerId = $domainMap[$domain];
            $provider = $this->getProvider($providerId);

            if ($provider !== null) {
                return [
                    'provider' => $provider['id'],
                    'name' => $provider['name'],
                    'is_known' => true,
                    'domain' => $domain,
                    'auth_type' => $provider['auth_type'] ?? 'password',
                    'oauth_provider' => $provider['oauth_provider'] ?? null,
                    'doc_url' => $provider['doc_url'] ?? null,
                    'instructions' => $provider['instructions'] ?? null,
                ];
            }
        }

        return $this->buildGenericResponse($domain);
    }

    /**
     * Get a provider configuration by its ID.
     *
     * @return array<string, mixed>|null
     */
    public function getProvider(string $id): ?array
    {
        $providers = config('mail_providers.providers', []);

        return $providers[$id] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function allProviders(): array
    {
        return config('mail_providers.providers', []);
    }

    /**
     * Build the fallback generic response for unknown providers or company domains.
     *
     * @return array{
     *     provider: string,
     *     name: string,
     *     is_known: bool,
     *     domain: string|null,
     *     doc_url: string|null,
     *     instructions: array{title: string, steps: list<string>}|null
     * }
     */
    private function buildGenericResponse(?string $domain): array
    {
        $generic = $this->getProvider('generic');

        return [
            'provider' => 'generic',
            'name' => $generic['name'] ?? 'Własny / Nieznany dostawca',
            'is_known' => false,
            'domain' => $domain,
            'auth_type' => 'password',
            'oauth_provider' => null,
            'doc_url' => null,
            'instructions' => null,
        ];
    }

    /**
     * Build and cache the domain-to-provider lookup map.
     *
     * @return array<string, string>
     */
    private function getDomainMap(): array
    {
        if ($this->domainMap !== null) {
            return $this->domainMap;
        }

        $map = [];
        $providers = config('mail_providers.providers', []);

        foreach ($providers as $id => $config) {
            if ($id === 'generic') {
                continue;
            }

            foreach ($config['domains'] ?? [] as $domain) {
                $map[strtolower(trim($domain))] = (string) $id;
            }
        }

        $this->domainMap = $map;

        return $this->domainMap;
    }
}
