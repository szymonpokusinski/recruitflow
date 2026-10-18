<?php

namespace App\Recruitment;

use App\Integrations\PracujPl\PracujUrl;
use App\Models\Recruitment\Offer;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OfferIdentity
{
    public static function url(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $parts = parse_url(trim($value));
        if (! isset($parts['host'])) {
            return null;
        }
        if (in_array(strtolower($parts['host']), ['pracuj.pl', 'www.pracuj.pl'], true)) {
            $id = self::pracujId($value);
            if ($id !== null) {
                return 'pracuj.pl:'.$id;
            }
        }
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (str_starts_with(strtolower($key), 'utm_') || in_array(strtolower($key), ['fbclid', 'gclid'], true)) {
                unset($query[$key]);
            }
        }
        ksort($query);

        return strtolower($parts['host']).rtrim($parts['path'] ?? '/', '/').($query ? '?'.http_build_query($query) : '');
    }

    public static function pracujId(?string $value): ?string
    {
        try {
            return $value ? PracujUrl::from($value)->offerId : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function title(array $data): string
    {
        return Str::lower(Str::ascii(trim($data['company'] ?? '').'|'.trim($data['title'] ?? '')));
    }

    public function duplicates(): array
    {
        $offers = Offer::all()->filter(fn ($offer) => ! isset($offer->data['mergedInto']));
        $result = [];
        foreach ($offers->where('origin', 'local') as $local) {
            foreach ($offers->where('origin', 'google_sheets') as $remote) {
                $url = self::url($local->data['offerUrl'] ?? null);
                $sameUrl = $url && $url === self::url($remote->data['offerUrl'] ?? null);
                $sameTitle = ! empty($local->data['company']) && ! empty($local->data['title']) && self::title($local->data) === self::title($remote->data);
                if ($sameUrl || $sameTitle) {
                    $result[] = ['local' => ['id' => $local->id, 'title' => $local->data['title'], 'company' => $local->data['company']], 'remote' => ['id' => $remote->id, 'title' => $remote->data['title'], 'company' => $remote->data['company']], 'reason' => $sameUrl ? 'url' : 'company_title'];
                }
            }
        }

        return $result;
    }
}
