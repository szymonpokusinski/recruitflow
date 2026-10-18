<?php

namespace App\Integrations\Google;

use App\Integrations\IntegrationException;
use App\Models\Integrations\DataSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GoogleSheetsGateway
{
    public function __construct(private GoogleOAuthService $oauth) {}

    public function request(DataSource $source, string $method, string $suffix = '', array $data = []): array
    {
        $source->refresh()->load('account');
        if ($method !== 'GET' && (! $source->enabled || $source->read_only || ! in_array(GoogleOAuthService::WRITE_SCOPE, $source->account->scopes ?? [], true))) {
            throw new IntegrationException('Źródło nie ma aktywnego uprawnienia do zapisu.', 'write_permission');
        }
        $id = SpreadsheetIdentifier::parse($source->external_id);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->oauth->accessToken($source->account, $attempt > 0);
            try {
                $client = Http::withToken($token)->connectTimeout(5)->timeout(config('integrations.http_timeout_seconds'));
                $response = $method === 'GET' ? $client->get('https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($id).$suffix, $data) : $client->post('https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($id).$suffix, $data);
            } catch (ConnectionException) {
                throw new IntegrationException('Brak połączenia lub timeout Google. Zmiana pozostaje zapisana lokalnie.', 'offline');
            }
            if ($response->status() === 401 && $attempt === 0) {
                continue;
            }
            if (! $response->successful()) {
                [$message, $reason] = match (true) {
                    $response->status() === 401 => ['Połącz ponownie konto Google.', 'reauthorization_required'],
                    in_array($response->status(), [403, 404]) => ['Brak uprawnień do arkusza lub arkusz jest niedostępny.', 'access_denied'],
                    $response->status() === 429 => ['Limit Google API. Operacja zostanie ponowiona później.', 'rate_limited'],
                    $response->status() >= 500 => ['Przejściowy błąd Google. Operacja zostanie ponowiona.', 'google_error'],
                    default => ['Google odrzucił zapis. Sprawdź schemat i walidacje komórek.', 'schema_invalid']
                };
                throw new IntegrationException($message, $reason);
            }

            return $response->json() ?? [];
        }
        throw new IntegrationException('Ponowne uwierzytelnienie jest wymagane.', 'reauthorization_required');
    }

    public static function range(string $title, string $range): string
    {
        return "'".str_replace("'", "''", $title)."'!".$range;
    }

    public function grid(DataSource $source, string $title, string $range): array
    {
        $document = $this->request($source, 'GET', '', ['ranges' => self::range($title, $range), 'fields' => 'properties(timeZone),sheets(properties(sheetId,title,gridProperties),data(startRow,startColumn,rowData(values(userEnteredValue,effectiveValue,dataValidation))))']);

        return $document['sheets'][0]['data'][0]['rowData'] ?? [];
    }

    public static function column(int $index): string
    {
        $value = '';
        do {
            $value = chr(65 + $index % 26).$value;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $value;
    }
}
