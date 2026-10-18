<?php

namespace App\Widgets;

use App\Models\Recruitment\Offer;

final class WidgetDeepLink
{
    public static function offer(string $id): string
    {
        return 'recruitflow://offer/'.$id;
    }

    public function path(string $url): ?string
    {
        if (strlen($url) > 256) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'recruitflow'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        if ($host === 'offers' && $path === '/new') {
            return '/offers?widget=new';
        }
        if ($host === 'activity' && $path === '') {
            return '/activity';
        }
        if ($host === 'refresh' && $path === '') {
            return '/settings?widget=refresh';
        }
        if ($host === 'offer' && preg_match('~^/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$~', $path, $match)) {
            $offer = Offer::find($match[1]);
            if ($offer && ! isset($offer->data['mergedInto'])) {
                return '/offers/'.$offer->id;
            }
        }

        return null;
    }
}
