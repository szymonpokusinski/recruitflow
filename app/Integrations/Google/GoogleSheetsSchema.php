<?php

namespace App\Integrations\Google;

use App\Integrations\IntegrationException;
use App\Models\Integrations\DataSource;

class GoogleSheetsSchema
{
    public function __construct(private GoogleSheetsGateway $gateway) {}

    public function inspect(DataSource $source): array
    {
        $metadata = $this->gateway->request($source, 'GET', '', ['fields' => 'properties(timeZone),sheets(properties(sheetId,title,gridProperties))']);
        $result = ['timezone' => $metadata['properties']['timeZone'] ?? 'UTC', 'roles' => []];
        $configurations = $source->configurations()->with('mappings')->get()->keyBy('sheet_id');
        foreach ($metadata['sheets'] ?? [] as $sheet) {
            $properties = $sheet['properties'];
            $configuration = $configurations[$properties['sheetId']] ?? null;
            if ($configuration && ! in_array($configuration->role, ['offers', 'applications', 'events', 'profiles'], true)) {
                continue;
            }
            // Unconfigured tabs are inspected only for the audit-log signature.
            $headerRow = $configuration?->header_row ?? 1;
            $rows = $this->gateway->grid($source, $properties['title'], $headerRow.':'.($headerRow + 2));
            $headers = [];
            foreach ($rows[0]['values'] ?? [] as $index => $cell) {
                $value = $cell['effectiveValue']['stringValue'] ?? $cell['userEnteredValue']['stringValue'] ?? '';
                if ($value !== '') {
                    if (isset($headers[$value])) {
                        throw new IntegrationException('Powtarzające się nagłówki uniemożliwiają bezpieczny zapis.', 'duplicate_headers');
                    }
                    $headers[$value] = $index;
                }
            }
            $role = $configuration?->role;
            if (! $role) {
                $role = isset($headers['change_id'], $headers['offer_key'], $headers['field']) ? 'history' : null;
            }
            if (! $role) {
                continue;
            }
            if (isset($result['roles'][$role])) {
                throw new IntegrationException('Kilka zakładek ma tę samą rolę. Popraw konfigurację.', 'schema_ambiguous');
            }
            $rules = [];
            $formulas = [];
            foreach (array_slice($rows, 1) as $offset => $row) {
                foreach ($headers as $header => $column) {
                    $cell = $row['values'][$column] ?? [];
                    if (isset($cell['dataValidation'])) {
                        $rules[$header] = $cell['dataValidation'];
                    }
                    if (isset($cell['userEnteredValue']['formulaValue'])) {
                        $formulas[$header] = ['row' => $headerRow + $offset, 'array' => str_contains(strtoupper($cell['userEnteredValue']['formulaValue']), 'ARRAYFORMULA')];
                    }
                }
            }
            $result['roles'][$role] = ['sheet_id' => $properties['sheetId'], 'title' => $properties['title'], 'header_row' => $headerRow, 'row_count' => $properties['gridProperties']['rowCount'] ?? 1000, 'headers' => $headers, 'validations' => $rules, 'formulas' => $formulas, 'profile' => $configuration?->schema_profile ?? 'recruitflow', 'mapping' => $configuration?->mappings->pluck('column_name', 'field')->all() ?? []];
        }

        return $result;
    }

    public function assertWritable(array $schema): void
    {
        foreach (['offers' => 'offer_key', 'applications' => 'application_id', 'events' => 'event_id', 'history' => 'change_id'] as $role => $key) {
            $table = $schema['roles'][$role] ?? null;
            if (! $table || $table['profile'] !== 'recruitflow' || ! isset($table['headers'][$key])) {
                throw new IntegrationException('Do zapisu wymagany jest zgodny schemat RecruitFlow (oferty, aplikacje, zdarzenia i historia zmian). Inne arkusze mogą pozostać źródłami odczytu.', 'schema_incompatible');
            }
        }
        foreach (['applications', 'events', 'history'] as $role) {
            if (! isset($schema['roles'][$role]['headers']['offer_key'])) {
                throw new IntegrationException('Brak klucza powiązanej oferty.', 'schema_incompatible');
            }
        }
    }
}
