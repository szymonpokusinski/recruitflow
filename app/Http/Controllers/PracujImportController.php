<?php

namespace App\Http\Controllers;

use App\Integrations\PracujPl\ImportException;
use App\Integrations\PracujPl\PracujPlParser;
use App\Integrations\PracujPl\PracujUrl;
use App\Models\Recruitment\Offer;
use App\Recruitment\OfferIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class PracujImportController extends Controller
{
    public function preview(Request $request, PracujPlParser $parser): JsonResponse
    {
        $values = $request->validate(['url' => ['required', 'string', 'max:2048']]);
        try {
            $url = PracujUrl::from($values['url']);
            $identity = OfferIdentity::url($url->url);
            $duplicate = Offer::query()->get()->first(fn (Offer $offer) => ! isset($offer->data['mergedInto']) && OfferIdentity::url($offer->data['offerUrl'] ?? null) === $identity);
            if ($duplicate) {
                return response()->json([
                    'status' => 'duplicate',
                    'draft' => null,
                    'duplicate' => ['id' => $duplicate->id, 'title' => $duplicate->data['title'] ?: 'Do uzupełnienia', 'company' => $duplicate->data['company'] ?? ''],
                ]);
            }
            $draft = $parser->preview($values['url']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['status' => 'unsupported_url', 'message' => $exception->getMessage(), 'draft' => null, 'duplicate' => null], 422);
        } catch (ImportException $exception) {
            return response()->json(['status' => $exception->reason, 'message' => $exception->getMessage(), 'draft' => null, 'duplicate' => null], $exception->httpStatus);
        }

        return response()->json([
            'status' => $draft->status,
            'draft' => $draft->toArray(),
            'duplicate' => null,
        ]);
    }
}
