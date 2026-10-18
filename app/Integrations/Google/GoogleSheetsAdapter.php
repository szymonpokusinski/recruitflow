<?php

namespace App\Integrations\Google;

use App\Integrations\Contracts\DataSourceAdapterInterface;
use App\Integrations\IntegrationException;
use App\Models\Integrations\DataSource;
use App\Models\Integrations\DataSourceConfiguration;
use Illuminate\Support\Facades\Http;

class GoogleSheetsAdapter implements DataSourceAdapterInterface
{
    public function __construct(private GoogleOAuthService $oauth) {}

    private function get(DataSource $source, string $suffix, array $query = []): array
    {
        $id = SpreadsheetIdentifier::parse($source->external_id);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->oauth->accessToken($source->account, $attempt === 1);
            try {
                $response = Http::withToken($token)->connectTimeout(5)->timeout(config('integrations.http_timeout_seconds'))->get('https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($id).$suffix, $query);
            } catch (\Throwable) {
                throw new IntegrationException('Brak połączenia z Google Sheets. Poprzedni cache pozostaje dostępny.', 'offline');
            }
            if ($response->status() === 401 && $attempt === 0) {
                continue;
            }
            if ($response->status() === 401) {
                $source->account->update(['status' => 'reauthorization_required']);
                throw new IntegrationException('Konto wymaga ponownego połączenia.', 'reauthorization_required');
            }
            if ($response->status() === 403) {
                throw new IntegrationException('Brak dostępu do arkusza lub Google Sheets API jest wyłączone.', 'access_denied');
            }
            if ($response->status() === 404) {
                throw new IntegrationException('Nie znaleziono arkusza lub konto nie ma do niego dostępu.', 'not_found');
            }
            if ($response->status() === 429) {
                throw new IntegrationException('Google ograniczył liczbę odczytów. Spróbuj później.', 'rate_limited');
            }
            if (! $response->successful()) {
                throw new IntegrationException('Google Sheets jest chwilowo niedostępne. Cache zachowano.', 'google_error');
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new IntegrationException('Nieprawidłowa odpowiedź Google Sheets.', 'invalid_response');
            }

            return $data;
        }
        throw new IntegrationException('Nie udało się odczytać arkusza.', 'google_error');
    }

    public function metadata(DataSource $source): array
    {
        $data = $this->get($source, '', ['fields' => 'spreadsheetId,properties(title,timeZone),sheets(properties(sheetId,title,gridProperties(rowCount,columnCount)))']);

        return ['title' => $data['properties']['title'] ?? '', 'timezone' => $data['properties']['timeZone'] ?? 'UTC', 'sheets' => array_map(fn ($sheet) => ['id' => $sheet['properties']['sheetId'], 'title' => $sheet['properties']['title'], 'row_count' => $sheet['properties']['gridProperties']['rowCount'] ?? 0], $data['sheets'] ?? [])];
    }

    private function title(DataSource $source, int $sheetId): string
    {
        $sheet = collect($this->metadata($source)['sheets'])->firstWhere('id', $sheetId);
        if (! $sheet) {
            throw new IntegrationException('Zakładka została usunięta. Popraw konfigurację źródła.', 'sheet_missing');
        }

        return $sheet['title'];
    }

    private function range(string $title, string $rows): string
    {
        return "'".str_replace("'", "''", $title)."'!".$rows;
    }

    public function headers(DataSource $source, int $sheetId, int $headerRow): array
    {
        $title = $this->title($source, $sheetId);
        $data = $this->get($source, '/values/'.rawurlencode($this->range($title, "$headerRow:$headerRow")), ['valueRenderOption' => 'FORMATTED_VALUE']);
        $headers = array_map(fn ($cell) => trim((string) $cell), $data['values'][0] ?? []);
        $named = array_values(array_filter($headers, fn ($cell) => $cell !== ''));
        if (count($named) !== count(array_unique($named))) {
            throw new IntegrationException('Nagłówki zakładki nie są jednoznaczne. Każda mapowana kolumna musi mieć unikalną nazwę.', 'duplicate_headers');
        }

        return ['title' => $title, 'columns' => $named];
    }

    public function rows(DataSource $source, DataSourceConfiguration $configuration): array
    {
        $metadata = $this->metadata($source);
        $sheet = collect($metadata['sheets'])->firstWhere('id', $configuration->sheet_id);
        if (! $sheet) {
            throw new IntegrationException('Zakładka została usunięta.', 'sheet_missing');
        }
        $title = $sheet['title'];
        $start = $configuration->header_row;
        $end = $start + config('integrations.max_rows') + 1;
        $data = $this->get($source, '/values/'.rawurlencode($this->range($title, "$start:$end")), ['valueRenderOption' => 'UNFORMATTED_VALUE', 'dateTimeRenderOption' => 'SERIAL_NUMBER']);
        $values = $data['values'] ?? [];
        if (count($values) > config('integrations.max_rows') + 1) {
            throw new IntegrationException('Zakładka przekracza limit bezpiecznego odczytu. Synchronizacja nie zmieniła cache.', 'row_limit');
        }
        $headers = array_map(fn ($value) => trim((string) $value), array_shift($values) ?? []);
        $named = array_filter($headers, fn ($cell) => $cell !== '');
        if (count($named) !== count(array_unique($named))) {
            throw new IntegrationException('Zakładka zawiera powtarzające się nagłówki.', 'duplicate_headers');
        }
        $rows = [];
        foreach ($values as $index => $valuesRow) {
            if (! array_filter($valuesRow, fn ($cell) => $cell !== '' && $cell !== null)) {
                continue;
            }
            $row = [];
            foreach ($headers as $column => $header) {
                if ($header !== '') {
                    $row[$header] = $valuesRow[$column] ?? '';
                }
            }
            $rows[] = ['row_number' => $start + $index + 1, 'values' => $row];
        }

        return ['title' => $title, 'timezone' => $metadata['timezone'], 'headers' => array_values($named), 'rows' => $rows];
    }
}
