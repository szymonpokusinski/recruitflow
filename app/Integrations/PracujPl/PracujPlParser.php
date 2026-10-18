<?php

namespace App\Integrations\PracujPl;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class PracujPlParser
{
    public function __construct(private SafePracujClient $client, private OfferNormalizer $normalizer) {}

    public function preview(string $input): OfferDraftDTO
    {
        try {
            $url = PracujUrl::from($input);
        } catch (InvalidArgumentException $exception) {
            throw new ImportException('unsupported_url', $exception->getMessage(), 422);
        }

        return $this->parse($url, $this->client->fetch($url)->body);
    }

    public function parse(PracujUrl $url, string $html): OfferDraftDTO
    {
        if (strlen($html) > 2_000_000) {
            throw new ImportException('response_too_large', 'Strona ogłoszenia przekracza limit.', 502);
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $next = $this->decode($xpath->query('//script[@id="__NEXT_DATA__"]')->item(0)?->textContent);
        $embedded = null;
        foreach ($next['props']['pageProps']['dehydratedState']['queries'] ?? [] as $query) {
            if (($query['queryKey'][0] ?? null) === 'jobOffer' && (string) ($query['queryKey'][1] ?? '') === $url->offerId) {
                $candidate = $query['state']['data'] ?? null;
                if (is_array($candidate) && (string) ($candidate['jobOfferWebId'] ?? '') === $url->offerId) {
                    $embedded = $candidate;
                }
                break;
            }
        }
        $jsonLd = null;
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $jsonLd = $this->jobPosting($this->decode($script->textContent));
            if ($jsonLd !== null) {
                break;
            }
        }
        if ($embedded === null && $jsonLd === null) {
            throw new ImportException('parse_error', 'Strona nie zawiera rozpoznanego ogłoszenia. Struktura serwisu mogła się zmienić.', 502);
        }
        $expires = $embedded['publicationDetails']['expirationDateTimeUtc'] ?? $jsonLd['validThrough'] ?? null;
        if (($embedded['publicationDetails']['isActive'] ?? true) === false || ($expires && strtotime($expires) !== false && strtotime($expires) < now()->getTimestamp())) {
            throw new ImportException('expired', 'To ogłoszenie wygasło.', 410);
        }

        try {
            return $this->normalizer->normalize($url, $embedded, $jsonLd);
        } catch (ImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Pracuj.pl offer structure could not be normalized', ['offer_id' => $url->offerId, 'error_type' => $exception::class]);
            throw new ImportException('parse_error', 'Struktura danych ogłoszenia jest nieobsługiwana.', 502, $exception);
        }
    }

    private function decode(?string $json): ?array
    {
        if (! $json) {
            return null;
        }
        $value = json_decode($json, true, 64);

        return is_array($value) ? $value : null;
    }

    private function jobPosting(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $type = $value['@type'] ?? null;
        if ($type === 'JobPosting' || (is_array($type) && in_array('JobPosting', $type, true))) {
            return $value;
        }
        $nodes = $value['@graph'] ?? (array_is_list($value) ? $value : []);
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (is_array($node) && ($found = $this->jobPosting($node))) {
                return $found;
            }
        }

        return null;
    }
}
